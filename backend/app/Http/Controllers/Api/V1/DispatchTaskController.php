<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispatchTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = DispatchTask::query()->with([
            'vehicle:id,plate_number,name',
            'driver:id,account,name',
            'orders:id,order_no,client_name,pickup_address,dropoff_address,cargo_category_id,status',
        ]);
        if ($user && $user->role === 'driver') {
            $query->where('driver_id', $user->id);
        }

        return response()->json(
            $query->latest()->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()?->role, ['admin', 'dispatcher'], true)) {
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

        $task = DispatchTask::query()->create($payload);

        return response()->json($task, 201);
    }

    public function show(Request $request, DispatchTask $dispatchTask): JsonResponse
    {
        if (! $this->canAccessTask($request, $dispatchTask)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $dispatchTask->loadMissing([
            'vehicle:id,plate_number,name',
            'driver:id,account,name',
            'orders:id,order_no,client_name,status',
        ]);

        return response()->json($dispatchTask);
    }

    public function showByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
        ]);

        $dispatchTask = DispatchTask::query()->findOrFail($payload['id']);
        if (! $this->canAccessTask($request, $dispatchTask)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $dispatchTask->loadMissing([
            'vehicle:id,plate_number,name',
            'driver:id,account,name',
            'orders:id,order_no,client_name,status',
        ]);

        return response()->json($dispatchTask);
    }

    public function update(Request $request, DispatchTask $dispatchTask): JsonResponse
    {
        if (! in_array($request->user()?->role, ['admin', 'dispatcher'], true)) {
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

        $dispatchTask->update($payload);

        return response()->json($dispatchTask->fresh());
    }

    public function updateByPayload(Request $request): JsonResponse
    {
        if (! in_array($request->user()?->role, ['admin', 'dispatcher'], true)) {
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

        $dispatchTask = DispatchTask::query()->findOrFail($payload['id']);
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
        $dispatchTask->update($payload);

        return response()->json($dispatchTask->fresh());
    }

    public function exceptionList(Request $request): JsonResponse
    {
        if (! in_array($request->user()?->role, ['admin', 'dispatcher'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'status' => ['nullable', 'in:pending,handled'],
            'task_no' => ['nullable', 'string', 'max:100'],
        ]);

        $targetStatus = $payload['status'] ?? 'pending';
        $keyword = trim((string) ($payload['task_no'] ?? ''));

        $tasks = DispatchTask::query()
            ->with(['vehicle:id,plate_number,name', 'driver:id,account,name'])
            ->latest()
            ->get()
            ->filter(function (DispatchTask $task) use ($targetStatus, $keyword): bool {
                if ($keyword !== '' && ! str_contains((string) $task->task_no, $keyword)) {
                    return false;
                }
                $exception = is_array($task->route_meta) ? ($task->route_meta['exception'] ?? null) : null;
                if (! is_array($exception)) {
                    return false;
                }
                return ($exception['status'] ?? null) === $targetStatus;
            })
            ->values();

        return response()->json([
            'data' => $tasks,
            'total' => $tasks->count(),
        ]);
    }

    public function handleException(Request $request): JsonResponse
    {
        if (! in_array($request->user()?->role, ['admin', 'dispatcher'], true)) {
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

        $task = DispatchTask::query()->findOrFail($payload['task_id']);
        $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
        $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : null;
        if (! $exception || ($exception['status'] ?? null) !== 'pending') {
            return response()->json(['message' => '当前任务没有待处理异常'], 422);
        }

        DB::transaction(function () use ($payload, $request, $task, $routeMeta, $exception): void {
            $oldVehicleId = (int) ($task->vehicle_id ?? 0);

            if ($payload['action'] === 'cancel') {
                $task->status = 'cancelled';
                if ($task->vehicle_id) {
                    Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'idle']);
                }
            } elseif ($payload['action'] === 'continue') {
                $task->status = 'in_progress';
                if ($task->vehicle_id) {
                    Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'busy']);
                }
            } elseif ($payload['action'] === 'reassign') {
                $vehicle = Vehicle::query()
                    ->where('id', (int) $payload['reassign_vehicle_id'])
                    ->where('status', 'idle')
                    ->with('driver:id,role,status')
                    ->first();
                if (! $vehicle || ! $vehicle->driver_id || ! $vehicle->driver || $vehicle->driver->role !== 'driver' || $vehicle->driver->status !== 'active') {
                    throw ValidationException::withMessages([
                        'reassign_vehicle_id' => '目标车辆必须为空闲且绑定启用司机',
                    ]);
                }

                $task->vehicle_id = (int) $vehicle->id;
                $task->driver_id = (int) $vehicle->driver_id;
                $task->status = 'assigned';

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
                'reassign_vehicle_id' => $payload['reassign_vehicle_id'] ?? null,
            ];

            $routeMeta['exception'] = array_merge($exception, [
                'status' => 'handled',
                'handled_at' => now()->toDateTimeString(),
                'handled_by' => (int) $request->user()->id,
                'handle_action' => $payload['action'],
                'handle_note' => $payload['handle_note'] ?? null,
                'reassign_vehicle_id' => $payload['reassign_vehicle_id'] ?? null,
                'history' => $history,
            ]);

            $task->route_meta = $routeMeta;
            $task->save();
        });

        return response()->json(
            $task->fresh(['vehicle:id,plate_number,name', 'driver:id,account,name'])
        );
    }

    private function canAccessTask(Request $request, DispatchTask $dispatchTask): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->role === 'admin' || $user->role === 'dispatcher') {
            return true;
        }

        if ($user->role === 'driver') {
            return (int) $dispatchTask->driver_id === (int) $user->id;
        }

        return false;
    }

    private function canModifyTask(DispatchTask $dispatchTask): bool
    {
        return ! $dispatchTask->waypoints()
            ->whereIn('status', ['arrived', 'completed'])
            ->exists();
    }
}
