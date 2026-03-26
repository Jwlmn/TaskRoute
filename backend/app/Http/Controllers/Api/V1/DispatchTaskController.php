<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DispatchTaskController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            DispatchTask::query()->latest()->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
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

    public function show(DispatchTask $dispatchTask): JsonResponse
    {
        return response()->json($dispatchTask);
    }

    public function update(Request $request, DispatchTask $dispatchTask): JsonResponse
    {
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

        $dispatchTask->update($payload);

        return response()->json($dispatchTask->fresh());
    }
}

