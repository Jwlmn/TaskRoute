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
        $payload = $request->validate(
            [
                'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
                'waypoint_id' => ['required', 'integer', 'exists:task_waypoints,id'],
                'document_type' => ['required', 'in:receipt,signoff,photo,exception'],
                'document_file' => ['nullable', 'file', 'max:5120'],
                'document_files' => ['nullable', 'array', 'min:1', 'max:9'],
                'document_files.*' => ['file', 'max:5120'],
                'remark' => ['nullable', 'string', 'max:255'],
            ],
            [
                'document_file.uploaded' => '文件上传失败，请检查单文件大小（建议小于 20MB）后重试',
                'document_files.*.uploaded' => '存在文件上传失败，请检查每张图片大小（建议小于 20MB）后重试',
                'document_file.max' => '单文件不能超过 5MB',
                'document_files.*.max' => '每个文件不能超过 5MB',
                'document_files.max' => '一次最多上传 9 张图片',
            ]
        );

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

        $files = [];
        if ($request->hasFile('document_files')) {
            $files = $request->file('document_files');
        } elseif ($request->hasFile('document_file')) {
            $files = [$request->file('document_file')];
        }

        if ($files === []) {
            return response()->json(['message' => '请至少上传一个文件'], 422);
        }

        $documents = DB::transaction(function () use ($payload, $task, $waypoint, $request, $files) {
            $created = [];
            foreach ($files as $file) {
                $path = $file->store('electronic-documents', 'public');
                $created[] = ElectronicDocument::query()->create([
                    'dispatch_task_id' => $task->id,
                    'task_waypoint_id' => $waypoint->id,
                    'uploaded_by' => $request->user()->id,
                    'document_type' => $payload['document_type'],
                    'file_path' => $path,
                    'meta' => [
                        'url' => Storage::disk('public')->url($path),
                        'remark' => $payload['remark'] ?? null,
                        'original_name' => $file->getClientOriginalName(),
                    ],
                    'uploaded_at' => now(),
                ]);
            }

            return ElectronicDocument::query()->whereIn('id', collect($created)->pluck('id')->all())->get();
        });

        if ($documents->count() === 1) {
            return response()->json(
                $documents->first()->loadMissing('waypoint:id,dispatch_task_id,sequence,node_type,address'),
                201
            );
        }

        return response()->json([
            'count' => $documents->count(),
            'documents' => $documents->load('waypoint:id,dispatch_task_id,sequence,node_type,address')->values(),
        ], 201);
    }
}
