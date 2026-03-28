<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\ElectronicDocument;
use App\Models\PrePlanOrder;
use App\Models\TaskWaypoint;
use App\Models\Vehicle;
use App\Services\Dispatch\SmartDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DriverTaskExecutionController extends Controller
{
    public function __construct(private readonly SmartDispatchService $smartDispatchService)
    {
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
        ]);

        $task = DispatchTask::query()
            ->with(['orders.cargoCategory', 'waypoints.documents', 'documents.waypoint'])
            ->findOrFail($payload['task_id']);

        if ((int) $task->driver_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->hydrateDocumentUrls($task, $request);

        return response()->json($task);
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
        ]);

        return DB::transaction(function () use ($payload, $request): JsonResponse {
            $task = DispatchTask::query()->lockForUpdate()->findOrFail($payload['task_id']);
            if ((int) $task->driver_id !== (int) $request->user()->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if (in_array($task->status, ['completed', 'cancelled'], true)) {
                return response()->json(['message' => '当前任务不可开始'], 422);
            }

            // 幂等：已接单或执行中重复点击开始，直接返回当前任务状态。
            if (in_array($task->status, ['accepted', 'in_progress'], true)) {
                if ($task->vehicle_id) {
                    Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'busy']);
                }
                return response()->json($task->fresh());
            }

            if ($task->status === 'assigned') {
                $task->status = 'accepted';
                $task->save();
            }
            if ($task->vehicle_id) {
                Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'busy']);
            }

            return response()->json($task->fresh());
        });
    }

    public function reportException(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
            'exception_type' => ['required', 'in:vehicle_breakdown,traffic_jam,customer_reject,address_change,goods_damage,other'],
            'description' => ['required', 'string', 'max:500'],
            'waypoint_id' => ['nullable', 'integer', 'exists:task_waypoints,id'],
        ]);

        return DB::transaction(function () use ($payload, $request): JsonResponse {
            $task = DispatchTask::query()->lockForUpdate()->findOrFail($payload['task_id']);
            if ((int) $task->driver_id !== (int) $request->user()->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (! in_array($task->status, ['accepted', 'in_progress'], true)) {
                return response()->json(['message' => '请先接单后再上报异常'], 422);
            }

            if (! empty($payload['waypoint_id'])) {
                $isWaypointBelongsTask = TaskWaypoint::query()
                    ->where('dispatch_task_id', $task->id)
                    ->where('id', (int) $payload['waypoint_id'])
                    ->exists();
                if (! $isWaypointBelongsTask) {
                    return response()->json(['message' => '节点不属于当前任务'], 422);
                }
            }

            $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
            $existingException = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : null;
            if (($existingException['status'] ?? null) === 'pending') {
                $sameRequest = ($existingException['type'] ?? null) === $payload['exception_type']
                    && ($existingException['description'] ?? null) === $payload['description']
                    && (int) ($existingException['reported_by'] ?? 0) === (int) $request->user()->id
                    && ((int) ($existingException['waypoint_id'] ?? 0) === (int) ($payload['waypoint_id'] ?? 0));
                if ($sameRequest) {
                    return response()->json($task->fresh());
                }
                return response()->json(['message' => '当前任务已有待处理异常，请勿重复上报'], 422);
            }

            $routeMeta['exception'] = [
                'status' => 'pending',
                'type' => $payload['exception_type'],
                'description' => $payload['description'],
                'waypoint_id' => $payload['waypoint_id'] ?? null,
                'reported_by' => (int) $request->user()->id,
                'reported_at' => now()->toDateTimeString(),
                'handled_at' => null,
                'handled_by' => null,
                'handle_action' => null,
                'handle_note' => null,
                'history' => array_values(array_merge(
                    is_array($routeMeta['exception']['history'] ?? null) ? $routeMeta['exception']['history'] : [],
                    [[
                        'event' => 'reported',
                        'type' => $payload['exception_type'],
                        'description' => $payload['description'],
                        'operator_id' => (int) $request->user()->id,
                        'occurred_at' => now()->toDateTimeString(),
                    ]]
                )),
            ];
            $task->route_meta = $routeMeta;
            $task->save();

            return response()->json($task->fresh());
        });
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
        if (! in_array($task->status, ['accepted', 'in_progress'], true)) {
            return response()->json(['message' => '请先接单后再执行节点操作'], 422);
        }
        if ($this->hasPendingException($task)) {
            return response()->json(['message' => '当前任务存在待处理异常，请等待调度处理后再执行节点'], 422);
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
            $task->orders()->update(['status' => 'in_progress']);
        }

        if ($task->vehicle_id) {
            Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'busy']);
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
        if (! in_array($task->status, ['accepted', 'in_progress'], true)) {
            return response()->json(['message' => '请先接单后再执行节点操作'], 422);
        }
        if ($this->hasPendingException($task)) {
            return response()->json(['message' => '当前任务存在待处理异常，请等待调度处理后再执行节点'], 422);
        }

        $waypoint = TaskWaypoint::query()
            ->where('dispatch_task_id', $task->id)
            ->where('id', $payload['waypoint_id'])
            ->firstOrFail();

        DB::transaction(function () use ($payload, $waypoint, $task): void {
            $task->refresh();
            $wasTaskCompleted = $task->status === 'completed';

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

            if ($pendingExists) {
                $task->status = 'in_progress';
                $task->save();
                $task->orders()->update(['status' => 'in_progress']);
                if ($task->vehicle_id) {
                    Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'busy']);
                }
                return;
            }

            $task->status = 'completed';
            $task->save();
            $task->loadMissing('orders');
            foreach ($task->orders as $order) {
                $freight = $this->calculateFreightAmount($order);
                $order->status = 'completed';
                $order->freight_amount = $freight['amount'];
                $order->freight_calculated_at = $freight['amount'] === null ? null : now();
                $order->meta = $this->mergeFreightMeta($order, $freight);
                $order->save();
            }

            if ($task->vehicle_id) {
                Vehicle::query()->where('id', (int) $task->vehicle_id)->update(['status' => 'idle']);
            }

            if (! $wasTaskCompleted) {
                $this->autoDispatchNextTrip($task);
            }
        });

        return response()->json($waypoint->fresh());
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $payload = $request->validate(
            [
                'task_id' => ['required', 'integer', 'exists:dispatch_tasks,id'],
                'waypoint_id' => ['required', 'integer', 'exists:task_waypoints,id'],
                'document_type' => ['required', 'in:pickup_note,dropoff_note,receipt,signoff,photo,exception'],
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
        if (! in_array($task->status, ['accepted', 'in_progress'], true)) {
            return response()->json(['message' => '请先接单后再上传单据'], 422);
        }
        if ($this->hasPendingException($task)) {
            return response()->json(['message' => '当前任务存在待处理异常，请等待调度处理后再上传单据'], 422);
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
                        'url' => $this->buildPublicFileUrl($request, $path),
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

    private function buildPublicFileUrl(Request $request, string $path): string
    {
        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        return $base.'/storage/'.ltrim($path, '/');
    }

    private function hasPendingException(DispatchTask $task): bool
    {
        $meta = is_array($task->route_meta) ? $task->route_meta : [];
        return ($meta['exception']['status'] ?? null) === 'pending';
    }

    private function calculateFreightAmount(PrePlanOrder $order): array
    {
        $scheme = (string) ($order->freight_calc_scheme ?? '');
        $unitPrice = (float) ($order->freight_unit_price ?? 0);
        if ($scheme === '' || $unitPrice <= 0) {
            return ['amount' => null, 'base_value' => null, 'unit_price' => $unitPrice, 'scheme' => $scheme];
        }

        $baseValue = null;
        if ($scheme === 'by_weight') {
            $baseValue = max(0, (float) $order->cargo_weight_kg) / 1000;
        } elseif ($scheme === 'by_volume') {
            $baseValue = max(0, (float) $order->cargo_volume_m3);
        } elseif ($scheme === 'by_trip') {
            $baseValue = max(1, (int) ($order->freight_trip_count ?? 1));
        } elseif ($scheme === 'by_loss_ton') {
            $lossKg = max(0, (float) ($order->freight_loss_ton_kg ?? 0) - (float) $order->cargo_weight_kg);
            $baseValue = $lossKg / 1000;
        }

        if ($baseValue === null) {
            return ['amount' => null, 'base_value' => null, 'unit_price' => $unitPrice, 'scheme' => $scheme];
        }

        return [
            'amount' => round($baseValue * $unitPrice, 2),
            'base_value' => round((float) $baseValue, 4),
            'unit_price' => round($unitPrice, 2),
            'scheme' => $scheme,
        ];
    }

    private function mergeFreightMeta(PrePlanOrder $order, array $freight): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $meta['freight_calc'] = [
            'scheme' => $freight['scheme'] ?? null,
            'base_value' => $freight['base_value'] ?? null,
            'unit_price' => $freight['unit_price'] ?? null,
            'amount' => $freight['amount'] ?? null,
            'calculated_at' => now()->toDateTimeString(),
        ];

        return $meta;
    }

    private function autoDispatchNextTrip(DispatchTask $completedTask): void
    {
        if (! $completedTask->vehicle_id) {
            return;
        }

        $vehicle = Vehicle::query()
            ->where('id', (int) $completedTask->vehicle_id)
            ->where('status', 'idle')
            ->whereNotNull('driver_id')
            ->whereHas('driver', fn ($query) => $query->where('role', 'driver')->where('status', 'active'))
            ->first();
        if (! $vehicle) {
            return;
        }

        $orders = PrePlanOrder::query()
            ->where('status', 'pending')
            ->whereDoesntHave('dispatchTasks', function ($query): void {
                $query->whereIn('dispatch_tasks.status', ['draft', 'assigned', 'accepted', 'in_progress']);
            })
            ->orderBy('expected_pickup_at')
            ->get();
        if ($orders->isEmpty()) {
            return;
        }

        $preview = $this->smartDispatchService->preview($orders, collect([$vehicle]));
        $assignment = collect($preview['assignments'] ?? [])
            ->first(fn ($item) => (int) ($item['vehicle_id'] ?? 0) === (int) $vehicle->id);
        if (! is_array($assignment) || empty($assignment['order_ids'])) {
            return;
        }

        $orderIds = collect($assignment['order_ids'])->map(fn ($id) => (int) $id)->values()->all();
        $dispatchMode = count($orderIds) > 1
            ? 'single_vehicle_multi_order'
            : 'single_vehicle_single_order';

        $task = DispatchTask::query()->create([
            'task_no' => 'DT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'vehicle_id' => (int) $vehicle->id,
            'driver_id' => (int) $vehicle->driver_id,
            'dispatcher_id' => $completedTask->dispatcher_id,
            'dispatch_mode' => $dispatchMode,
            'status' => 'assigned',
            'estimated_distance_km' => (float) ($assignment['estimated_distance_km'] ?? 0),
            'estimated_fuel_l' => (float) ($assignment['estimated_fuel_l'] ?? 0),
            'route_meta' => array_merge(
                $assignment['route_meta'] ?? [],
                [
                    'estimated_duration_min' => $assignment['estimated_duration_min'] ?? null,
                    'compartment_plan' => $assignment['compartment_plan'] ?? [],
                    'auto_next_trip' => true,
                ]
            ),
        ]);

        $syncData = [];
        foreach ($orderIds as $index => $orderId) {
            $syncData[$orderId] = ['sequence' => $index + 1];
        }
        $task->orders()->sync($syncData);
        $this->buildTaskWaypoints($task->id, $orderIds);

        PrePlanOrder::query()->whereIn('id', $orderIds)->update(['status' => 'scheduled']);
        Vehicle::query()->where('id', (int) $vehicle->id)->update(['status' => 'busy']);
    }

    private function buildTaskWaypoints(int $taskId, array $orderIds): void
    {
        $orders = PrePlanOrder::query()
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        DB::table('task_waypoints')->where('dispatch_task_id', $taskId)->delete();

        $rows = [];
        $sequence = 1;
        foreach ($orderIds as $orderId) {
            $order = $orders->get($orderId);
            if (! $order) {
                continue;
            }

            $rows[] = [
                'dispatch_task_id' => $taskId,
                'node_type' => 'pickup',
                'sequence' => $sequence++,
                'address' => sprintf(
                    '订单%s｜装货:%s｜卸货:%s',
                    $order->order_no,
                    $order->pickup_address,
                    $order->dropoff_address
                ),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('task_waypoints')->insert($rows);
        }
    }

    private function hydrateDocumentUrls(DispatchTask $task, Request $request): void
    {
        foreach ($task->documents as $doc) {
            $meta = is_array($doc->meta) ? $doc->meta : [];
            $meta['url'] = $this->buildPublicFileUrl($request, (string) $doc->file_path);
            $doc->meta = $meta;
        }

        foreach ($task->waypoints as $waypoint) {
            foreach ($waypoint->documents as $doc) {
                $meta = is_array($doc->meta) ? $doc->meta : [];
                $meta['url'] = $this->buildPublicFileUrl($request, (string) $doc->file_path);
                $doc->meta = $meta;
            }
        }
    }
}
