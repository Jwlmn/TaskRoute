<?php

namespace App\Services\Dispatch;

use App\Models\PrePlanOrder;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmartDispatchService
{
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
                $assignments[] = [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'plate_number' => $vehicle->plate_number,
                    'order_ids' => $packResult['orders']->pluck('id')->values(),
                    'estimated_distance_km' => $this->estimateDistance($packResult['orders']->count()),
                    'estimated_fuel_l' => $this->estimateFuel($packResult['orders']->sum('cargo_weight_kg')),
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

        foreach ($orders as $order) {
            if (! $this->isVehicleAllowedCargo($vehicle->id, (int) $order->cargo_category_id)) {
                continue;
            }

            if ($selected->isNotEmpty() && $this->isIncompatibleWithSelected($selected, (int) $order->cargo_category_id)) {
                continue;
            }

            $nextWeight = $weight + (float) $order->cargo_weight_kg;
            $nextVolume = $volume + (float) $order->cargo_volume_m3;

            if ($nextWeight > (float) $vehicle->max_weight_kg || $nextVolume > (float) $vehicle->max_volume_m3) {
                continue;
            }

            $selected->push($order);
            $weight = $nextWeight;
            $volume = $nextVolume;
        }

        return ['orders' => $selected];
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

