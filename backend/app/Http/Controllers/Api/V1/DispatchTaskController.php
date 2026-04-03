<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\SystemMessage;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Auth\DataScopeService;
use App\Services\Dispatch\ExceptionSlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispatchTaskController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly ExceptionSlaService $exceptionSlaService,
    )
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
            'handled_by_keyword' => ['nullable', 'string', 'max:100'],
            'handled_by_me' => ['nullable', 'boolean'],
        ]);

        $targetStatus = $payload['status'] ?? 'pending';
        $keyword = trim((string) ($payload['task_no'] ?? ''));
        $exceptionType = $payload['exception_type'] ?? null;
        $handleAction = $payload['handle_action'] ?? null;
        $handledByKeyword = trim((string) ($payload['handled_by_keyword'] ?? ''));
        $handledByMe = (bool) ($payload['handled_by_me'] ?? false);
        $currentUserId = (int) ($request->user()?->id ?? 0);

        $tasks = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $request->user())
            ->with([
                'vehicle:id,plate_number,name,site_id',
                'driver:id,account,name',
                'orders:id,order_no,client_name,pickup_address,dropoff_address,status,audit_status',
            ])
            ->latest()
            ->get()
            ->map(function (DispatchTask $task) use ($targetStatus): DispatchTask {
                $syncSla = $targetStatus === 'pending';
                return $this->exceptionSlaService->syncTaskExceptionSla($task, $syncSla);
            })
            ->filter(function (DispatchTask $task) use ($targetStatus, $keyword, $exceptionType, $handleAction, $handledByKeyword, $handledByMe, $currentUserId): bool {
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
                if ($handledByMe && ($exception['handled_by'] ?? null) !== $currentUserId) {
                    return false;
                }
                if ($handledByKeyword !== '') {
                    $handledByName = (string) ($exception['handled_by_name'] ?? '');
                    $handledByAccount = (string) ($exception['handled_by_account'] ?? '');
                    if (! str_contains($handledByName, $handledByKeyword) && ! str_contains($handledByAccount, $handledByKeyword)) {
                        return false;
                    }
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
            $user = $request->user();
            $task = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $request->user())
                ->lockForUpdate()
                ->findOrFail($payload['task_id']);
            $task->loadMissing([
                'vehicle:id,plate_number,name',
                'driver:id,account,name',
            ]);
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
            $oldVehiclePlateNumber = $task->vehicle?->plate_number;
            $oldVehicleName = $task->vehicle?->name;
            $oldDriverAccount = $task->driver?->account;
            $oldDriverName = $task->driver?->name;
            $nextVehiclePlateNumber = $oldVehiclePlateNumber;
            $nextVehicleName = $oldVehicleName;
            $nextDriverAccount = $oldDriverAccount;
            $nextDriverName = $oldDriverName;

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
                    ->with('driver:id,account,name,role,status')
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

                $nextVehiclePlateNumber = $vehicle->plate_number;
                $nextVehicleName = $vehicle->name;
                $nextDriverAccount = $vehicle->driver?->account;
                $nextDriverName = $vehicle->driver?->name;
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
                'operator_id' => (int) $user->id,
                'operator_account' => $user->account,
                'operator_name' => $user->name,
                'occurred_at' => now()->toDateTimeString(),
                'previous_task_status' => $oldTaskStatus,
                'previous_vehicle_id' => $oldVehicleId > 0 ? $oldVehicleId : null,
                'previous_vehicle_plate_number' => $oldVehiclePlateNumber,
                'previous_vehicle_name' => $oldVehicleName,
                'previous_driver_id' => $oldDriverId > 0 ? $oldDriverId : null,
                'previous_driver_account' => $oldDriverAccount,
                'previous_driver_name' => $oldDriverName,
                'current_task_status' => (string) $task->status,
                'current_vehicle_id' => $task->vehicle_id ? (int) $task->vehicle_id : null,
                'current_vehicle_plate_number' => $nextVehiclePlateNumber,
                'current_vehicle_name' => $nextVehicleName,
                'current_driver_id' => $task->driver_id ? (int) $task->driver_id : null,
                'current_driver_account' => $nextDriverAccount,
                'current_driver_name' => $nextDriverName,
                'reassign_vehicle_id' => $payload['reassign_vehicle_id'] ?? null,
            ];

            $routeMeta['exception'] = $this->exceptionSlaService->annotateException(array_merge($exception, [
                'status' => 'handled',
                'handled_at' => now()->toDateTimeString(),
                'handled_by' => (int) $user->id,
                'handled_by_account' => $user->account,
                'handled_by_name' => $user->name,
                'handle_action' => $payload['action'],
                'handle_note' => $payload['handle_note'] ?? null,
                'reassign_vehicle_id' => $payload['reassign_vehicle_id'] ?? null,
                'previous_task_status' => $oldTaskStatus,
                'previous_vehicle_id' => $oldVehicleId > 0 ? $oldVehicleId : null,
                'previous_vehicle_plate_number' => $oldVehiclePlateNumber,
                'previous_vehicle_name' => $oldVehicleName,
                'previous_driver_id' => $oldDriverId > 0 ? $oldDriverId : null,
                'previous_driver_account' => $oldDriverAccount,
                'previous_driver_name' => $oldDriverName,
                'current_task_status' => (string) $task->status,
                'current_vehicle_id' => $task->vehicle_id ? (int) $task->vehicle_id : null,
                'current_vehicle_plate_number' => $nextVehiclePlateNumber,
                'current_vehicle_name' => $nextVehicleName,
                'current_driver_id' => $task->driver_id ? (int) $task->driver_id : null,
                'current_driver_account' => $nextDriverAccount,
                'current_driver_name' => $nextDriverName,
                'history' => $history,
            ]), now()->toImmutable());

            $task->route_meta = $routeMeta;
            $task->save();
            $this->notifyDriversForHandledException(
                task: $task,
                action: (string) $payload['action'],
                oldDriverId: $oldDriverId > 0 ? $oldDriverId : null,
                newDriverId: $task->driver_id ? (int) $task->driver_id : null,
                handleNote: $payload['handle_note'] ?? null,
            );
            return response()->json(
                $task->fresh(['vehicle:id,plate_number,name', 'driver:id,account,name'])
            );
        });
    }

    public function assignExceptionHandler(Request $request): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['admin', 'dispatcher'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'assigned_handler_id' => ['required', 'integer', 'exists:users,id'],
            'assign_note' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($payload, $request): JsonResponse {
            $operator = $request->user();
            $task = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $operator)
                ->lockForUpdate()
                ->findOrFail((int) $payload['task_id']);

            $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
            $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : null;
            if (! $exception) {
                return response()->json(['message' => '当前任务没有异常记录'], 422);
            }
            if (($exception['status'] ?? null) !== 'pending') {
                return response()->json(['message' => '仅待处理异常可改派责任人'], 422);
            }

            $targetHandler = User::query()
                ->where('id', (int) $payload['assigned_handler_id'])
                ->where('status', 'active')
                ->whereIn('role', ['admin', 'dispatcher'])
                ->first();
            if (! $targetHandler) {
                return response()->json(['message' => '目标责任人不可用'], 422);
            }
            if (
                ! $operator->hasRole('admin')
                && $targetHandler->id !== $operator->id
                && ! $this->dataScopeService->applyUserScope(User::query(), $operator)->where('id', $targetHandler->id)->exists()
            ) {
                return response()->json(['message' => '目标责任人不在当前可管理范围内'], 403);
            }

            $currentHandlerId = (int) ($exception['assigned_handler_id'] ?? 0);
            if ($currentHandlerId === (int) $targetHandler->id) {
                return response()->json($task->fresh(['vehicle:id,plate_number,name', 'driver:id,account,name']));
            }

            $history = is_array($exception['history'] ?? null) ? $exception['history'] : [];
            $history[] = [
                'event' => 'manual_assign',
                'operator_id' => (int) $operator->id,
                'operator_account' => $operator->account,
                'operator_name' => $operator->name,
                'previous_assigned_handler_id' => $currentHandlerId > 0 ? $currentHandlerId : null,
                'assigned_handler_id' => (int) $targetHandler->id,
                'assigned_handler_account' => $targetHandler->account,
                'assigned_handler_name' => $targetHandler->name,
                'assign_note' => $payload['assign_note'] ?? null,
                'occurred_at' => now()->toDateTimeString(),
            ];

            $routeMeta['exception'] = array_merge($exception, [
                'assigned_handler_id' => (int) $targetHandler->id,
                'assigned_handler_account' => $targetHandler->account,
                'assigned_handler_name' => $targetHandler->name,
                'assigned_at' => now()->toDateTimeString(),
                'assigned_by' => (int) $operator->id,
                'assigned_by_account' => $operator->account,
                'assigned_by_name' => $operator->name,
                'assigned_reason' => 'manual_assign',
                'assign_note' => $payload['assign_note'] ?? null,
                'history' => $history,
            ]);
            $task->route_meta = $routeMeta;
            $task->save();

            SystemMessage::query()->create([
                'user_id' => (int) $targetHandler->id,
                'message_type' => 'dispatch_notice',
                'title' => '异常任务已指派给你',
                'content' => "任务 {$task->task_no} 异常已由 {$operator->name} 指派给你，请及时处理。",
                'meta' => [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'exception_type' => $exception['type'] ?? null,
                    'notice_type' => 'exception_manual_assign',
                    'assign_note' => $payload['assign_note'] ?? null,
                ],
            ]);

            return response()->json($task->fresh(['vehicle:id,plate_number,name', 'driver:id,account,name']));
        });
    }

    private function notifyDriversForHandledException(
        DispatchTask $task,
        string $action,
        ?int $oldDriverId,
        ?int $newDriverId,
        ?string $handleNote = null,
    ): void {
        $taskId = (int) $task->id;
        $taskNo = (string) $task->task_no;
        $noteText = $handleNote ? "，备注：{$handleNote}" : '';

        if ($action === 'continue' && $newDriverId) {
            $this->createDispatchNotice(
                userId: $newDriverId,
                title: '任务异常已处理',
                content: "任务 {$taskNo} 的异常已处理，请继续执行{$noteText}",
                taskId: $taskId,
                taskNo: $taskNo,
                action: $action,
            );
            return;
        }

        if ($action === 'cancel' && $oldDriverId) {
            $this->createDispatchNotice(
                userId: $oldDriverId,
                title: '任务已取消',
                content: "任务 {$taskNo} 因异常已取消，请停止执行{$noteText}",
                taskId: $taskId,
                taskNo: $taskNo,
                action: $action,
            );
            return;
        }

        if ($action === 'reassign') {
            if ($oldDriverId) {
                $this->createDispatchNotice(
                    userId: $oldDriverId,
                    title: '任务已改派',
                    content: "任务 {$taskNo} 已改派给其他司机，请停止执行{$noteText}",
                    taskId: $taskId,
                    taskNo: $taskNo,
                    action: $action,
                );
            }
            if ($newDriverId) {
                $this->createDispatchNotice(
                    userId: $newDriverId,
                    title: '收到改派任务',
                    content: "任务 {$taskNo} 已改派给你，请及时查看并执行{$noteText}",
                    taskId: $taskId,
                    taskNo: $taskNo,
                    action: $action,
                );
            }
        }
    }

    private function createDispatchNotice(
        int $userId,
        string $title,
        string $content,
        int $taskId,
        string $taskNo,
        string $action,
    ): void {
        SystemMessage::query()->create([
            'user_id' => $userId,
            'message_type' => 'dispatch_notice',
            'title' => $title,
            'content' => $content,
            'meta' => [
                'task_id' => $taskId,
                'task_no' => $taskNo,
                'handle_action' => $action,
            ],
        ]);
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
