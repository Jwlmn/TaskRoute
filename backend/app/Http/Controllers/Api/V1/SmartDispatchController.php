<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\PrePlanOrder;
use App\Models\Vehicle;
use App\Services\Dispatch\SmartDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmartDispatchController extends Controller
{
    public function __construct(private readonly SmartDispatchService $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer', 'exists:pre_plan_orders,id'],
            'vehicle_ids' => ['nullable', 'array'],
            'vehicle_ids.*' => ['integer', 'exists:vehicles,id'],
        ]);

        $orders = $this->resolveOrders($payload['order_ids'] ?? null);
        $vehicles = $this->resolveVehicles($payload['vehicle_ids'] ?? null);

        if ($orders->isEmpty()) {
            return response()->json(['message' => '没有可调度的预计划单'], 422);
        }

        if ($vehicles->isEmpty()) {
            return response()->json(['message' => '没有可调度的车辆'], 422);
        }

        return response()->json(
            $this->service->preview($orders, $vehicles)
        );
    }

    public function createTasks(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer', 'exists:pre_plan_orders,id'],
            'vehicle_ids' => ['nullable', 'array'],
            'vehicle_ids.*' => ['integer', 'exists:vehicles,id'],
        ]);

        $orders = $this->resolveOrders($payload['order_ids'] ?? null);
        $vehicles = $this->resolveVehicles($payload['vehicle_ids'] ?? null);
        $preview = $this->service->preview($orders, $vehicles);

        $createdTaskIds = DB::transaction(function () use ($preview, $request): array {
            $taskIds = [];
            foreach ($preview['assignments'] as $assignment) {
                $task = DispatchTask::query()->create([
                    'task_no' => 'DT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'vehicle_id' => $assignment['vehicle_id'],
                    'dispatcher_id' => $request->user()->id,
                    'dispatch_mode' => $assignment['dispatch_mode'],
                    'status' => 'assigned',
                    'estimated_distance_km' => $assignment['estimated_distance_km'],
                    'estimated_fuel_l' => $assignment['estimated_fuel_l'],
                    'route_meta' => array_merge(
                        $assignment['route_meta'] ?? [
                            'strategy' => 'rule_based_v1',
                            'optimizer' => 'rule_based',
                        ],
                        [
                            'compartment_plan' => $assignment['compartment_plan'] ?? [],
                            'estimated_duration_min' => $assignment['estimated_duration_min'] ?? null,
                        ]
                    ),
                ]);

                $syncData = [];
                foreach ($assignment['order_ids'] as $index => $orderId) {
                    $syncData[$orderId] = ['sequence' => $index + 1];
                }
                $task->orders()->sync($syncData);

                PrePlanOrder::query()
                    ->whereIn('id', $assignment['order_ids'])
                    ->update(['status' => 'scheduled']);

                $taskIds[] = $task->id;
            }

            return $taskIds;
        });

        return response()->json([
            'created_task_ids' => $createdTaskIds,
            'preview' => $preview,
        ], 201);
    }

    private function resolveOrders(?array $orderIds)
    {
        return PrePlanOrder::query()
            ->whereIn('status', ['pending', 'scheduled'])
            ->when($orderIds, fn ($query) => $query->whereIn('id', $orderIds))
            ->orderBy('expected_pickup_at')
            ->get();
    }

    private function resolveVehicles(?array $vehicleIds)
    {
        return Vehicle::query()
            ->where('status', 'idle')
            ->when($vehicleIds, fn ($query) => $query->whereIn('id', $vehicleIds))
            ->orderByDesc('max_weight_kg')
            ->get();
    }
}
