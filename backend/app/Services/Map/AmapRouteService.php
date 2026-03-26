<?php

namespace App\Services\Map;

use App\Models\PrePlanOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AmapRouteService
{
    public function optimize(Collection $orders): ?array
    {
        if (! $this->isEnabled() || $orders->isEmpty()) {
            return null;
        }

        $points = $this->resolveRoutePoints($orders);
        if (count($points) < 2) {
            return null;
        }

        $origin = $points[0];
        $destination = $points[count($points) - 1];
        $waypoints = array_slice($points, 1, -1);

        try {
            $response = Http::timeout(8)->get('https://restapi.amap.com/v3/direction/driving', [
                'key' => $this->key(),
                'origin' => $origin,
                'destination' => $destination,
                'waypoints' => $waypoints === [] ? null : implode(';', $waypoints),
                'extensions' => 'base',
                'strategy' => 0,
            ]);

            if (! $response->ok()) {
                return null;
            }

            $body = $response->json();
            if (($body['status'] ?? '0') !== '1') {
                return null;
            }

            $path = $body['route']['paths'][0] ?? null;
            if (! is_array($path)) {
                return null;
            }

            $distanceKm = round(((float) ($path['distance'] ?? 0)) / 1000, 2);
            $durationMin = (int) round(((float) ($path['duration'] ?? 0)) / 60);

            return [
                'estimated_distance_km' => max(0.1, $distanceKm),
                'estimated_duration_min' => max(1, $durationMin),
                'route_meta' => [
                    'optimizer' => 'amap',
                    'amap_distance_m' => (float) ($path['distance'] ?? 0),
                    'amap_duration_s' => (float) ($path['duration'] ?? 0),
                    'amap_tolls' => (float) ($path['tolls'] ?? 0),
                    'point_count' => count($points),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('Amap route optimize failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveRoutePoints(Collection $orders): array
    {
        $points = [];
        foreach ($orders as $order) {
            if (! $order instanceof PrePlanOrder) {
                continue;
            }

            $pickupPoint = $this->geocodeAddress($order->pickup_address);
            if ($pickupPoint !== null) {
                $points[] = $pickupPoint;
            }

            $dropoffPoint = $this->geocodeAddress($order->dropoff_address);
            if ($dropoffPoint !== null) {
                $points[] = $dropoffPoint;
            }
        }

        return array_values(array_unique($points));
    }

    private function geocodeAddress(string $address): ?string
    {
        $cacheKey = 'amap:geo:'.md5($address);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout(5)->get('https://restapi.amap.com/v3/geocode/geo', [
                'key' => $this->key(),
                'address' => $address,
            ]);
            if (! $response->ok()) {
                return null;
            }

            $body = $response->json();
            if (($body['status'] ?? '0') !== '1') {
                return null;
            }

            $location = $body['geocodes'][0]['location'] ?? null;
            if (! is_string($location) || $location === '') {
                return null;
            }

            Cache::put($cacheKey, $location, now()->addHours(12));

            return $location;
        } catch (\Throwable $e) {
            Log::warning('Amap geocode failed', [
                'address' => $address,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function isEnabled(): bool
    {
        if ($this->key() === '') {
            return false;
        }

        if (app()->environment('testing') && ! (bool) config('services.amap.enable_in_testing', false)) {
            return false;
        }

        return true;
    }

    private function key(): string
    {
        return (string) config('services.amap.web_key', '');
    }
}
