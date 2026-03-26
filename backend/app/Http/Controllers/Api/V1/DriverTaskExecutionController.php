<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\ElectronicDocument;
use App\Models\TaskWaypoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DriverTaskExecutionController extends Controller
{
    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
        ]);

        $task = DispatchTask::query()
            ->with(['orders', 'waypoints.documents', 'documents.waypoint'])
            ->findOrFail($payload['task_id']);

        if ((int) $task->driver_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($task);
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
        ]);

        $task = DispatchTask::query()->findOrFail($payload['task_id']);
        if ((int) $task->driver_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (in_array($task->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => '当前任务不可开始'], 422);
        }

        if ($task->status === 'assigned') {
            $task->status = 'accepted';
            $task->save();
        }

        return response()->json($task->fresh());
    }

    public function arriveWaypoint(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'waypoint_id' => ['required', 'integer', 'exists:task_waypoints,id'],
            'lng' => ['nullable', 'numeric'],
            'lat' => ['nullable', 'numeric'],
        ]);

        $task = DispatchTask::query()->findOrFail($payload['task_id']);
        if ((int) $task->driver_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $waypoint = TaskWaypoint::query()
            ->where('dispatch_task_id', $task->id)
            ->where('id', $payload['waypoint_id'])
            ->firstOrFail();

        if ($waypoint->status === 'completed') {
            return response()->json($waypoint);
        }

        $waypoint->update([
            'status' => 'arrived',
            'lng' => $payload['lng'] ?? $waypoint->lng,
            'lat' => $payload['lat'] ?? $waypoint->lat,
            'arrived_at' => now(),
        ]);

        if (in_array($task->status, ['assigned', 'accepted'], true)) {
            $task->status = 'in_progress';
            $task->save();
        }

        return response()->json($waypoint->fresh());
    }

    public function completeWaypoint(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'waypoint_id' => ['required', 'integer', 'exists:task_waypoints,id'],
            'lng' => ['nullable', 'numeric'],
            'lat' => ['nullable', 'numeric'],
        ]);

        $task = DispatchTask::query()->findOrFail($payload['task_id']);
        if ((int) $task->driver_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $waypoint = TaskWaypoint::query()
            ->where('dispatch_task_id', $task->id)
            ->where('id', $payload['waypoint_id'])
            ->firstOrFail();

        if ($waypoint->status !== 'completed') {
            $waypoint->update([
                'status' => 'completed',
                'lng' => $payload['lng'] ?? $waypoint->lng,
                'lat' => $payload['lat'] ?? $waypoint->lat,
                'arrived_at' => $waypoint->arrived_at ?? now(),
                'completed_at' => now(),
            ]);
        }

        $pendingExists = TaskWaypoint::query()
            ->where('dispatch_task_id', $task->id)
            ->where('status', '!=', 'completed')
            ->exists();
        $task->status = $pendingExists ? 'in_progress' : 'completed';
        $task->save();

        return response()->json($waypoint->fresh());
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'waypoint_id' => ['required', 'integer', 'exists:task_waypoints,id'],
            'document_type' => ['required', 'in:receipt,signoff,photo,exception'],
            'document_file' => ['required', 'file', 'max:5120'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $task = DispatchTask::query()->findOrFail($payload['task_id']);
        if ((int) $task->driver_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $waypoint = TaskWaypoint::query()
            ->where('dispatch_task_id', $task->id)
            ->where('id', $payload['waypoint_id'])
            ->first();
        if (! $waypoint) {
            return response()->json(['message' => '节点不属于当前任务'], 422);
        }

        $alreadyUploaded = ElectronicDocument::query()
            ->where('task_waypoint_id', $waypoint->id)
            ->exists();
        if ($alreadyUploaded) {
            return response()->json(['message' => '该节点已上传单据，请勿重复上传'], 422);
        }

        $path = $request->file('document_file')->store('electronic-documents', 'public');

        $document = DB::transaction(function () use ($payload, $task, $waypoint, $request, $path) {
            return ElectronicDocument::query()->create([
                'dispatch_task_id' => $task->id,
                'task_waypoint_id' => $waypoint->id,
                'uploaded_by' => $request->user()->id,
                'document_type' => $payload['document_type'],
                'file_path' => $path,
                'meta' => [
                    'url' => Storage::disk('public')->url($path),
                    'remark' => $payload['remark'] ?? null,
                ],
                'uploaded_at' => now(),
            ]);
        });

        return response()->json($document->loadMissing('waypoint:id,dispatch_task_id,sequence,node_type,address'), 201);
    }
}
