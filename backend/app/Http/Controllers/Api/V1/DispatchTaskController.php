<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\Vehicle;
use App\Services\Auth\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispatchTaskController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole(['admin', 'dispatcher', 'driver'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:assigned,accepted,in_progress,completed,cancelled'],
            'status_group' => ['nullable', 'in:assigned,in_progress,completed'],
        ]);
        $keyword = trim((string) ($payload['keyword'] ?? ''));

        $query = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $user)->with([
            'vehicle:id,plate_number,name,site_id',
            'driver:id,account,name',
            'orders:id,order_no,client_name,pickup_site_id,pickup_address,pickup_contact_name,pickup_contact_phone,dropoff_site_id,dropoff_address,dropoff_contact_name,dropoff_contact_phone,cargo_category_id,status',
        ]);
        if ($payload['status'] ?? null) {
            $query->where('dispatch_tasks.status', (string) $payload['status']);
        } elseif (($payload['status_group'] ?? null) === 'assigned') {
            $query->where('dispatch_tasks.status', 'assigned');
        } elseif (($payload['status_group'] ?? null) === 'in_progress') {
            $query->whereIn('dispatch_tasks.status', ['accepted', 'in_progress']);
        } elseif (($payload['status_group'] ?? null) === 'completed') {
            $query->whereIn('dispatch_tasks.status', ['completed', 'cancelled']);
        }
        if ($keyword !== '') {
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('dispatch_tasks.task_no', 'like', "%{$keyword}%")
                    ->orWhere('dispatch_tasks.dispatch_mode', 'like', "%{$keyword}%")
                    ->orWhereHas('vehicle', function ($vehicleQuery) use ($keyword): void {
                        $vehicleQuery->where('vehicles.plate_number', 'like', "%{$keyword}%")
                            ->orWhere('vehicles.name', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('driver', function ($driverQuery) use ($keyword): void {
                        $driverQuery->where('users.account', 'like', "%{$keyword}%")
                            ->orWhere('users.name', 'like', "%{$keyword}%");
                    });
            });
        }

        return response()->json(
            $query->latest()->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['admin', 'dispatcher'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'dispatcher_id' => ['nullable', 'integer', 'exists:users,id'],
            'dispatch_mode' => ['nullable', 'in:single_vehicle_single_order,single_vehicle_multi_order,multi_vehicle_single_order,multi_vehicle_multi_order'],
            'estimated_distance_km' => ['nullable', 'numeric', 'min:0'],
            'estimated_fuel_l' => ['nullable', 'numeric', 'min:0'],
            'route_meta' => ['nullable', 'array'],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
        ]);

        $payload['task_no'] = 'DT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        if (array_key_exists('vehicle_id', $payload) && $payload['vehicle_id'] && ! $this->dataScopeService->canAccessSite(
            $request->user(),
            Vehicle::query()->where('id', (int) $payload['vehicle_id'])->value('site_id')
        )) {
            return response()->json(['message' => '当前账号不可创建该站点任务'], 403);
        }

        $task = DispatchTask::query()->create($payload);

        return response()->json($task, 201);
    }

    public function show(Request $request, DispatchTask $dispatchTask): JsonResponse
    {
        if (! $this->canAccessTask($request, $dispatchTask)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $dispatchTask->loadMissing([
            'vehicle:id,plate_number,name,site_id',
            'driver:id,account,name',
            'orders:id,order_no,client_name,pickup_site_id,pickup_address,pickup_contact_name,pickup_contact_phone,dropoff_site_id,dropoff_address,dropoff_contact_name,dropoff_contact_phone,status',
        ]);

        return response()->json($dispatchTask);
    }

    public function showByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
        ]);

        $dispatchTask = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $request->user())
            ->findOrFail($payload['id']);
        if (! $this->canAccessTask($request, $dispatchTask)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $dispatchTask->loadMissing([
            'vehicle:id,plate_number,name,site_id',
            'driver:id,account,name',
            'orders:id,order_no,client_name,pickup_site_id,pickup_address,pickup_contact_name,pickup_contact_phone,dropoff_site_id,dropoff_address,dropoff_contact_name,dropoff_contact_phone,status',
        ]);

        return response()->json($dispatchTask);
    }

    public function update(Request $request, DispatchTask $dispatchTask): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['admin', 'dispatcher'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $this->canAccessTask($request, $dispatchTask)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $this->canModifyTask($dispatchTask)) {
            return response()->json(['message' => '任务节点已到达/完成，当前任务不可修改'], 422);
        }

        $payload = $request->validate([
            'vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'dispatcher_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'dispatch_mode' => ['sometimes', 'in:single_vehicle_single_order,single_vehicle_multi_order,multi_vehicle_single_order,multi_vehicle_multi_order'],
            'status' => ['sometimes', 'in:draft,assigned,accepted,in_progress,completed,cancelled'],
            'estimated_distance_km' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimated_fuel_l' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'route_meta' => ['sometimes', 'nullable', 'array'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            'planned_end_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('vehicle_id', $payload) && ! array_key_exists('driver_id', $payload)) {
            $payload['driver_id'] = $payload['vehicle_id']
                ? Vehicle::query()->where('id', (int) $payload['vehicle_id'])->value('driver_id')
                : null;
        }
        if (array_key_exists('vehicle_id', $payload) && $payload['vehicle_id'] && ! $this->dataScopeService->canAccessSite(
            $request->user(),
            Vehicle::query()->where('id', (int) $payload['vehicle_id'])->value('site_id')
        )) {
            return response()->json(['message' => '当前账号不可修改为该站点任务'], 403);
        }

        $dispatchTask->update($payload);

        return response()->json($dispatchTask->fresh());
    }

    public function updateByPayload(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['admin', 'dispatcher'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'dispatcher_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'dispatch_mode' => ['sometimes', 'in:single_vehicle_single_order,single_vehicle_multi_order,multi_vehicle_single_order,multi_vehicle_multi_order'],
            'status' => ['sometimes', 'in:draft,assigned,accepted,in_progress,completed,cancelled'],
            'estimated_distance_km' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimated_fuel_l' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'route_meta' => ['sometimes', 'nullable', 'array'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            'planned_end_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $dispatchTask = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $request->user())
            ->findOrFail($payload['id']);
        if (! $this->canAccessTask($request, $dispatchTask)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (! $this->canModifyTask($dispatchTask)) {
            return response()->json(['message' => '任务节点已到达/完成，当前任务不可修改'], 422);
        }

        unset($payload['id']);
        if (array_key_exists('vehicle_id', $payload) && ! array_key_exists('driver_id', $payload)) {
            $payload['driver_id'] = $payload['vehicle_id']
                ? Vehicle::query()->where('id', (int) $payload['vehicle_id'])->value('driver_id')
                : null;
        }
        if (array_key_exists('vehicle_id', $payload) && $payload['vehicle_id'] && ! $this->dataScopeService->canAccessSite(
            $request->user(),
            Vehicle::query()->where('id', (int) $payload['vehicle_id'])->value('site_id')
        )) {
            return response()->json(['message' => '当前账号不可修改为该站点任务'], 403);
        }
        $dispatchTask->update($payload);

        return response()->json($dispatchTask->fresh());
    }

    public function exceptionList(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['admin', 'dispatcher'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'status' => ['nullable', 'in:pending,handled'],
            'task_no' => ['nullable', 'string', 'max:100'],
            'exception_type' => ['nullable', 'in:vehicle_breakdown,traffic_jam,customer_reject,address_change,goods_damage,other'],
            'handle_action' => ['nullable', 'in:continue,cancel,reassign'],
        ]);

        $targetStatus = $payload['status'] ?? 'pending';
        $keyword = trim((string) ($payload['task_no'] ?? ''));
        $exceptionType = $payload['exception_type'] ?? null;
        $handleAction = $payload['handle_action'] ?? null;

        $tasks = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $request->user())
            ->with(['vehicle:id,plate_number,name,site_id', 'driver:id,account,name'])
            ->latest()
            ->get()
            ->filter(function (DispatchTask $task) use ($targetStatus, $keyword, $exceptionType, $handleAction): bool {
                if ($keyword !== '' && ! str_contains((string) $task->task_no, $keyword)) {
                    return false;
                }
                $exception = is_array($task->route_meta) ? ($task->route_meta['exception'] ?? null) : null;
                if (! is_array($exception)) {
                    return false;
                }
                if (($exception['status'] ?? null) !== $targetStatus) {
                    return false;
                }
                if ($exceptionType && ($exception['type'] ?? null) !== $exceptionType) {
                    return false;
                }
                if ($handleAction && ($exception['handle_action'] ?? null) !== $handleAction) {
                    return false;
                }

                return true;
            })
            ->values();

        return response()->json([
            'data' => $tasks,
            'total' => $tasks->count(),
        ]);
    }

    public function orderList(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['admin', 'dispatcher', 'driver'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,scheduled,in_progress,completed,cancelled'],
        ]);

        $dispatchTask = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $request->user())
            ->findOrFail((int) $payload['task_id']);
        if (! $this->canAccessTask($request, $dispatchTask)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $keyword = trim((string) ($payload['keyword'] ?? ''));
        $orders = $dispatchTask->orders()
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('order_no', 'like', "%{$keyword}%")
                        ->orWhere('client_name', 'like', "%{$keyword}%")
                        ->orWhere('pickup_address', 'like', "%{$keyword}%")
                        ->orWhere('dropoff_address', 'like', "%{$keyword}%");
                });
            })
            ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('dispatch_task_orders.sequence')
            ->get([
                'pre_plan_orders.id',
                'pre_plan_orders.order_no',
                'pre_plan_orders.client_name',
                'pre_plan_orders.pickup_site_id',
                'pre_plan_orders.pickup_address',
                'pre_plan_orders.dropoff_site_id',
                'pre_plan_orders.dropoff_address',
                'pre_plan_orders.pickup_contact_name',
                'pre_plan_orders.pickup_contact_phone',
                'pre_plan_orders.dropoff_contact_name',
                'pre_plan_orders.dropoff_contact_phone',
                'pre_plan_orders.cargo_weight_kg',
                'pre_plan_orders.cargo_volume_m3',
                'pre_plan_orders.status',
                'pre_plan_orders.audit_status',
                'dispatch_task_orders.sequence',
            ]);

        return response()->json([
            'task_id' => (int) $dispatchTask->id,
            'task_no' => (string) $dispatchTask->task_no,
            'data' => $orders,
            'total' => $orders->count(),
        ]);
    }

    public function handleException(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['admin', 'dispatcher'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'action' => ['required', 'in:continue,cancel,reassign'],
            'handle_note' => ['nullable', 'string', 'max:500'],
            'reassign_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        if ($payload['action'] === 'reassign' && empty($payload['reassign_vehicle_id'])) {
            return response()->json(['message' => '改派必须选择目标车辆'], 422);
        }
        if ($payload['action'] !== 'reassign' && ! empty($payload['reassign_vehicle_id'])) {
            return response()->json(['message' => '仅改派操作可设置目标车辆'], 422);
        }

        return DB::transaction(function () use ($payload, $request): JsonResponse {
            $task = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $request->user())
                ->lockForUpdate()
                ->findOrFail($payload['task_id']);
            $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
            $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : null;
            if (! $exception) {
                return response()->json(['message' => '当前任务没有异常记录'], 422);
            }
            if (($exception['status'] ?? null) !== 'pending') {
                $alreadyHandled = ($exception['status'] ?? null) === 'handled';
                $sameAction = ($exception['handle_action'] ?? null) === $payload['action'];
                $sameReassignVehicle = ((int) ($exception['reassign_vehicle_id'] ?? 0) === (int) ($payload['reassign_vehicle_id'] ?? 0));
                if ($alreadyHandled && $sameAction && $sameReassignVehicle) {
                    return response()->json(
                        $task->fresh(['vehicle:id,plate_number,name', 'driver:id,account,name'])
                    );
                }
                return response()->json(['message' => '当前异常已处理，请勿重复提交不同处理动作'], 422);
            }

            $oldVehicleId = (int) ($task->vehicle_id ?? 0);
            $oldDriverId = (int) ($task->driver_id ?? 0);
            $oldTaskStatus = (string) ($task->status ?? '');

            if ($payload['action'] === 'cancel') {
                $task->status = 'cancelled';
                $task->orders()
                    ->whereIn('pre_plan_orders.status', ['pending', 'scheduled', 'in_progress'])
                    ->update(['status' => 'cancelled']);
                if ($task->vehicle_id) {
                    Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'idle']);
                }
            } elseif ($payload['action'] === 'continue') {
                if ($task->vehicle_id) {
                    Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'busy']);
                }
            } elseif ($payload['action'] === 'reassign') {
                $vehicle = Vehicle::query()
                    ->where('id', (int) $payload['reassign_vehicle_id'])
                    ->where('status', 'idle')
                    ->with('driver:id,role,status')
                    ->first();
                if (! $vehicle || ! $this->dataScopeService->canAccessSite($request->user(), (int) $vehicle->site_id)) {
                    throw ValidationException::withMessages([
                        'reassign_vehicle_id' => '目标车辆不在当前账号可管理范围内',
                    ]);
                }
                if (! $vehicle || ! $vehicle->driver_id || ! $vehicle->driver || $vehicle->driver->role !== 'driver' || $vehicle->driver->status !== 'active') {
                    throw ValidationException::withMessages([
                        'reassign_vehicle_id' => '目标车辆必须为空闲且绑定启用司机',
                    ]);
                }

                $task->vehicle_id = (int) $vehicle->id;
                $task->driver_id = (int) $vehicle->driver_id;
                $task->status = 'assigned';
                $task->orders()
                    ->whereIn('pre_plan_orders.status', ['pending', 'scheduled', 'in_progress'])
                    ->update(['status' => 'scheduled']);

                if ($oldVehicleId > 0 && $oldVehicleId !== (int) $vehicle->id) {
                    Vehicle::query()->where('id', $oldVehicleId)->update(['status' => 'idle']);
                }
                Vehicle::query()->where('id', (int) $vehicle->id)->update(['status' => 'busy']);
            }

            $history = is_array($exception['history'] ?? null) ? $exception['history'] : [];
            $history[] = [
                'event' => 'handled',
                'action' => $payload['action'],
                'handle_note' => $payload['handle_note'] ?? null,
                'operator_id' => (int) $request->user()->id,
                'occurred_at' => now()->toDateTimeString(),
                'previous_task_status' => $oldTaskStatus,
                'previous_vehicle_id' => $oldVehicleId > 0 ? $oldVehicleId : null,
                'previous_driver_id' => $oldDriverId > 0 ? $oldDriverId : null,
                'current_task_status' => (string) $task->status,
                'current_vehicle_id' => $task->vehicle_id ? (int) $task->vehicle_id : null,
                'current_driver_id' => $task->driver_id ? (int) $task->driver_id : null,
                'reassign_vehicle_id' => $payload['reassign_vehicle_id'] ?? null,
            ];

            $routeMeta['exception'] = array_merge($exception, [
                'status' => 'handled',
                'handled_at' => now()->toDateTimeString(),
                'handled_by' => (int) $request->user()->id,
                'handle_action' => $payload['action'],
                'handle_note' => $payload['handle_note'] ?? null,
                'reassign_vehicle_id' => $payload['reassign_vehicle_id'] ?? null,
                'previous_task_status' => $oldTaskStatus,
                'previous_vehicle_id' => $oldVehicleId > 0 ? $oldVehicleId : null,
                'previous_driver_id' => $oldDriverId > 0 ? $oldDriverId : null,
                'current_task_status' => (string) $task->status,
                'current_vehicle_id' => $task->vehicle_id ? (int) $task->vehicle_id : null,
                'current_driver_id' => $task->driver_id ? (int) $task->driver_id : null,
                'history' => $history,
            ]);

            $task->route_meta = $routeMeta;
            $task->save();
            return response()->json(
                $task->fresh(['vehicle:id,plate_number,name', 'driver:id,account,name'])
            );
        });
    }

    private function canAccessTask(Request $request, DispatchTask $dispatchTask): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        $scopedTask = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $user)
            ->whereKey($dispatchTask->id)
            ->exists();

        return $scopedTask;
    }

    private function canModifyTask(DispatchTask $dispatchTask): bool
    {
        return ! $dispatchTask->waypoints()
            ->whereIn('status', ['arrived', 'completed'])
            ->exists();
    }
}
