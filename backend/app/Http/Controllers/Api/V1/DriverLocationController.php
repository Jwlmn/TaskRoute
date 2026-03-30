<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\DriverLocation;
use App\Services\Auth\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverLocationController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function report(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'dispatch_task_id' => ['nullable', 'integer', 'exists:dispatch_tasks,id'],
            'lng' => ['required', 'numeric'],
            'lat' => ['required', 'numeric'],
            'speed_kmh' => ['nullable', 'numeric', 'min:0'],
            'located_at' => ['nullable', 'date'],
        ]);

        $driverId = (int) $request->user()->id;
        if (! empty($payload['dispatch_task_id'])) {
            $task = DispatchTask::query()->findOrFail($payload['dispatch_task_id']);
            if ((int) $task->driver_id !== $driverId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $location = DriverLocation::query()->create([
            'driver_id' => $driverId,
            'dispatch_task_id' => $payload['dispatch_task_id'] ?? null,
            'lng' => (float) $payload['lng'],
            'lat' => (float) $payload['lat'],
            'speed_kmh' => array_key_exists('speed_kmh', $payload) ? (float) $payload['speed_kmh'] : null,
            'located_at' => $payload['located_at'] ?? now(),
        ]);

        return response()->json($location, 201);
    }

    public function latest(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'dispatch_task_id' => ['nullable', 'integer', 'exists:dispatch_tasks,id'],
        ]);

        $locations = DriverLocation::query()
            ->with(['driver:id,account,name', 'task:id,task_no,status'])
            ->whereHas('driver.vehicle', fn ($query) => $this->dataScopeService->applyVehicleScope($query, $request->user()))
            ->when(
                $payload['dispatch_task_id'] ?? null,
                fn ($query, $taskId) => $query
                    ->where('dispatch_task_id', $taskId)
                    ->whereHas('task', fn ($taskQuery) => $this->dataScopeService->applyDispatchTaskScope($taskQuery, $request->user()))
            )
            ->orderByDesc('located_at')
            ->limit(1000)
            ->get()
            ->unique('driver_id')
            ->values();

        return response()->json($locations);
    }

    public function trajectory(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:users,id'],
            'dispatch_task_id' => ['nullable', 'integer', 'exists:dispatch_tasks,id'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:1000'],
        ]);

        $user = $request->user();
        if ($user?->role === 'driver' && (int) $payload['driver_id'] !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $limit = (int) ($payload['limit'] ?? 200);
        $rows = DriverLocation::query()
            ->where('driver_id', (int) $payload['driver_id'])
            ->whereHas('driver.vehicle', fn ($query) => $this->dataScopeService->applyVehicleScope($query, $user))
            ->when($payload['dispatch_task_id'] ?? null, fn ($query, $taskId) => $query->where('dispatch_task_id', $taskId))
            ->orderByDesc('located_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json($rows);
    }
}
