<?php

namespace App\Services\Dispatch;

use App\Models\PrePlanOrder;
use App\Models\Vehicle;
use App\Services\Map\AmapRouteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmartDispatchService
{
    public function __construct(private readonly AmapRouteService $amapRouteService)
    {
    }

    public function preview(Collection $orders, Collection $vehicles): array
    {
        $remainingOrders = $orders->values();
        $assignments = [];
        $unassigned = [];

        foreach ($vehicles as $vehicle) {
            if ($remainingOrders->isEmpty()) {
                break;
            }

            $packResult = $this->packOrdersForVehicle($remainingOrders, $vehicle);
            if ($packResult['orders']->isNotEmpty()) {
                $estimatedDistanceKm = $this->estimateDistance($packResult['orders']->count());
                $estimatedDurationMin = max(10, (int) round($estimatedDistanceKm * 2.5));
                $optimizer = 'rule_based';
                $routeMeta = [
                    'optimizer' => $optimizer,
                    'strategy' => 'rule_based_v1',
                ];

                $optimized = $this->amapRouteService->optimize($packResult['orders']);
                if ($optimized !== null) {
                    $estimatedDistanceKm = (float) $optimized['estimated_distance_km'];
                    $estimatedDurationMin = (int) $optimized['estimated_duration_min'];
                    $optimizer = 'amap';
                    $routeMeta = array_merge($routeMeta, $optimized['route_meta'] ?? []);
                }

                $assignments[] = [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'plate_number' => $vehicle->plate_number,
                    'order_ids' => $packResult['orders']->pluck('id')->values(),
                    'compartment_plan' => $packResult['compartment_plan'],
                    'estimated_distance_km' => $estimatedDistanceKm,
                    'estimated_duration_min' => $estimatedDurationMin,
                    'estimated_fuel_l' => $this->estimateFuel($packResult['orders']->sum('cargo_weight_kg')),
                    'route_meta' => $routeMeta,
                    'optimizer' => $optimizer,
                    'dispatch_mode' => $packResult['orders']->count() > 1
                        ? 'single_vehicle_multi_order'
                        : 'single_vehicle_single_order',
                ];
                $remainingOrders = $remainingOrders
                    ->reject(fn (PrePlanOrder $order) => $packResult['orders']->contains('id', $order->id))
                    ->values();
            }
        }

        foreach ($remainingOrders as $order) {
            $unassigned[] = [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'reason' => '无可用车辆满足禁混、承运或容量约束',
            ];
        }

        return [
            'assignments' => $assignments,
            'unassigned' => $unassigned,
        ];
    }

    private function packOrdersForVehicle(Collection $orders, Vehicle $vehicle): array
    {
        $selected = collect();
        $weight = 0.0;
        $volume = 0.0;
        $compartments = $this->resolveVehicleCompartments($vehicle);
        $compartmentPlan = [];

        foreach ($orders as $order) {
            if (! $this->isVehicleAllowedCargo($vehicle->id, (int) $order->cargo_category_id)) {
                continue;
            }

            if ($selected->isNotEmpty() && $this->isIncompatibleWithSelected($selected, (int) $order->cargo_category_id)) {
                continue;
            }

            $nextWeight = $weight + (float) $order->cargo_weight_kg;
            $nextVolume = $volume + (float) $order->cargo_volume_m3;

            if ($nextWeight > (float) $vehicle->max_weight_kg) {
                continue;
            }

            $assignedCompartmentNo = null;
            if ($compartments->isNotEmpty()) {
                $targetIndex = $this->resolveCompartmentIndexForOrder(
                    $compartments,
                    (int) $order->cargo_category_id,
                    (float) $order->cargo_volume_m3
                );
                if ($targetIndex === null) {
                    continue;
                }

                $targetCompartment = $compartments->get($targetIndex);
                $targetCompartment['remaining_m3'] = max(
                    0,
                    (float) $targetCompartment['remaining_m3'] - (float) $order->cargo_volume_m3
                );
                $compartments->put($targetIndex, $targetCompartment);
                $assignedCompartmentNo = (int) $targetCompartment['no'];
            } elseif ($nextVolume > (float) $vehicle->max_volume_m3) {
                continue;
            }

            $selected->push($order);
            $weight = $nextWeight;
            $volume = $nextVolume;
            if ($assignedCompartmentNo !== null) {
                $compartmentPlan[] = [
                    'order_id' => (int) $order->id,
                    'compartment_no' => $assignedCompartmentNo,
                    'cargo_category_id' => (int) $order->cargo_category_id,
                    'volume_m3' => (float) $order->cargo_volume_m3,
                ];
            }
        }

        return [
            'orders' => $selected,
            'compartment_plan' => $compartmentPlan,
        ];
    }

    private function resolveVehicleCompartments(Vehicle $vehicle): Collection
    {
        $meta = $vehicle->meta ?? [];
        $enabled = (bool) ($meta['compartment_enabled'] ?? false);
        if (! $enabled) {
            return collect();
        }

        $rawCompartments = $meta['compartments'] ?? [];
        if (! is_array($rawCompartments) || $rawCompartments === []) {
            return collect();
        }

        return collect($rawCompartments)
            ->map(function ($item, $index): array {
                $allowedIds = collect($item['allowed_cargo_category_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)
                    ->values()
                    ->all();

                return [
                    'no' => (int) ($item['no'] ?? ($index + 1)),
                    'remaining_m3' => (float) ($item['capacity_m3'] ?? 0),
                    'allowed_cargo_category_ids' => $allowedIds,
                ];
            })
            ->filter(fn ($item) => $item['remaining_m3'] > 0)
            ->values();
    }

    private function resolveCompartmentIndexForOrder(
        Collection $compartments,
        int $cargoCategoryId,
        float $volumeM3
    ): ?int {
        foreach ($compartments as $index => $compartment) {
            if ((float) $compartment['remaining_m3'] < $volumeM3) {
                continue;
            }

            $allowedIds = $compartment['allowed_cargo_category_ids'] ?? [];
            if ($allowedIds !== [] && ! in_array($cargoCategoryId, $allowedIds, true)) {
                continue;
            }

            return (int) $index;
        }

        return null;
    }

    private function isVehicleAllowedCargo(int $vehicleId, int $cargoCategoryId): bool
    {
        $denyExists = DB::table('vehicle_cargo_rules')
            ->where('vehicle_id', $vehicleId)
            ->where('cargo_category_id', $cargoCategoryId)
            ->where('rule_type', 'deny')
            ->exists();

        if ($denyExists) {
            return false;
        }

        $hasAllowRules = DB::table('vehicle_cargo_rules')
            ->where('vehicle_id', $vehicleId)
            ->where('rule_type', 'allow')
            ->exists();

        if (! $hasAllowRules) {
            return true;
        }

        return DB::table('vehicle_cargo_rules')
            ->where('vehicle_id', $vehicleId)
            ->where('cargo_category_id', $cargoCategoryId)
            ->where('rule_type', 'allow')
            ->exists();
    }

    private function isIncompatibleWithSelected(Collection $selected, int $cargoCategoryId): bool
    {
        $selectedCategoryIds = $selected->pluck('cargo_category_id')->map(fn ($id) => (int) $id)->all();
        if ($selectedCategoryIds === []) {
            return false;
        }

        return DB::table('cargo_incompatibilities')
            ->where(function ($query) use ($selectedCategoryIds, $cargoCategoryId): void {
                $query->whereIn('cargo_category_id', $selectedCategoryIds)
                    ->where('incompatible_with_id', $cargoCategoryId);
            })
            ->orWhere(function ($query) use ($selectedCategoryIds, $cargoCategoryId): void {
                $query->where('cargo_category_id', $cargoCategoryId)
                    ->whereIn('incompatible_with_id', $selectedCategoryIds);
            })
            ->exists();
    }

    private function estimateDistance(int $stopCount): float
    {
        return max(1, $stopCount) * 12.5;
    }

    private function estimateFuel(float $weightKg): float
    {
        return max(8, round(8 + ($weightKg / 1000) * 1.5, 2));
    }
}
