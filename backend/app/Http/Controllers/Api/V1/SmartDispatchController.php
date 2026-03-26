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

        $createdTaskIds = $this->createTasksFromAssignments($preview['assignments'], (int) $request->user()->id, false);

        return response()->json([
            'created_task_ids' => $createdTaskIds,
            'preview' => $preview,
        ], 201);
    }

    public function manualCreateTasks(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'assignments.*.order_ids' => ['required', 'array', 'min:1'],
            'assignments.*.order_ids.*' => ['integer', 'exists:pre_plan_orders,id'],
            'assignments.*.estimated_distance_km' => ['nullable', 'numeric', 'min:0'],
            'assignments.*.estimated_fuel_l' => ['nullable', 'numeric', 'min:0'],
            'assignments.*.estimated_duration_min' => ['nullable', 'integer', 'min:1'],
            'assignments.*.route_meta' => ['nullable', 'array'],
            'assignments.*.compartment_plan' => ['nullable', 'array'],
        ]);

        $this->validateManualAssignments($payload['assignments']);

        $createdTaskIds = $this->createTasksFromAssignments(
            $payload['assignments'],
            (int) $request->user()->id,
            true
        );

        return response()->json([
            'created_task_ids' => $createdTaskIds,
            'assignments' => $payload['assignments'],
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

    private function validateManualAssignments(array $assignments): void
    {
        $vehicleIds = collect($assignments)->pluck('vehicle_id')->values();
        if ($vehicleIds->duplicates()->isNotEmpty()) {
            abort(422, '同一车辆不能在一次下发中创建多条任务');
        }

        $allOrderIds = collect($assignments)
            ->pluck('order_ids')
            ->flatten(1)
            ->map(fn ($id) => (int) $id)
            ->values();
        if ($allOrderIds->duplicates()->isNotEmpty()) {
            abort(422, '同一预计划单不能重复分配');
        }

        $pendingOrderCount = PrePlanOrder::query()
            ->whereIn('id', $allOrderIds->all())
            ->whereIn('status', ['pending', 'scheduled'])
            ->count();
        if ($pendingOrderCount !== $allOrderIds->count()) {
            abort(422, '存在不可下发的预计划单（状态非待调度/已排程）');
        }

        $idleVehicleCount = Vehicle::query()
            ->whereIn('id', $vehicleIds->all())
            ->where('status', 'idle')
            ->count();
        if ($idleVehicleCount !== $vehicleIds->count()) {
            abort(422, '存在不可用车辆（非空闲状态）');
        }
    }

    private function createTasksFromAssignments(array $assignments, int $dispatcherId, bool $manualAdjusted): array
    {
        return DB::transaction(function () use ($assignments, $dispatcherId, $manualAdjusted): array {
            $taskIds = [];
            foreach ($assignments as $assignment) {
                $orderIds = collect($assignment['order_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all();
                if ($orderIds === []) {
                    continue;
                }

                $dispatchMode = count($orderIds) > 1
                    ? 'single_vehicle_multi_order'
                    : 'single_vehicle_single_order';

                $task = DispatchTask::query()->create([
                    'task_no' => 'DT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'vehicle_id' => (int) $assignment['vehicle_id'],
                    'dispatcher_id' => $dispatcherId,
                    'dispatch_mode' => $dispatchMode,
                    'status' => 'assigned',
                    'estimated_distance_km' => (float) ($assignment['estimated_distance_km'] ?? 0),
                    'estimated_fuel_l' => (float) ($assignment['estimated_fuel_l'] ?? 0),
                    'route_meta' => array_merge(
                        $assignment['route_meta'] ?? [
                            'strategy' => 'rule_based_v1',
                            'optimizer' => 'rule_based',
                        ],
                        [
                            'compartment_plan' => $assignment['compartment_plan'] ?? [],
                            'estimated_duration_min' => $assignment['estimated_duration_min'] ?? null,
                            'manual_adjusted' => $manualAdjusted,
                        ]
                    ),
                ]);

                $syncData = [];
                foreach ($orderIds as $index => $orderId) {
                    $syncData[$orderId] = ['sequence' => $index + 1];
                }
                $task->orders()->sync($syncData);

                PrePlanOrder::query()
                    ->whereIn('id', $orderIds)
                    ->update(['status' => 'scheduled']);

                $taskIds[] = $task->id;
            }

            return $taskIds;
        });
    }
}
