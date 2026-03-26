<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DispatchTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = DispatchTask::query()->with([
            'vehicle:id,plate_number,name',
            'driver:id,account,name',
            'orders:id,order_no,client_name,pickup_address,dropoff_address,cargo_category_id',
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
            'orders:id,order_no,client_name',
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
            'orders:id,order_no,client_name',
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
