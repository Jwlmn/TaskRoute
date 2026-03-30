<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CargoCategory;
use App\Models\FreightRateTemplate;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\SystemMessage;
use App\Services\Auth\DataScopeService;
use App\Services\Freight\FreightTemplateMatcherService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PrePlanOrderController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly FreightTemplateMatcherService $freightTemplateMatcherService,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,scheduled,in_progress,completed,cancelled'],
            'audit_status' => ['nullable', 'in:pending_approval,approved,rejected'],
            'is_locked' => ['nullable', 'boolean'],
            'cargo_category_id' => ['nullable', 'integer', 'exists:cargo_categories,id'],
            'pickup_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'trace_type' => ['nullable', 'in:origin,split,merge'],
            'expected_pickup_from' => ['nullable', 'date'],
            'expected_pickup_to' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->scopedOrderQuery($request)
                ->when($payload['keyword'] ?? null, function ($query, $keyword): void {
                    $kw = trim((string) $keyword);
                    if ($kw === '') {
                        return;
                    }
                    $query->where(function ($sub) use ($kw): void {
                        $sub->where('order_no', 'like', "%{$kw}%")
                            ->orWhere('client_name', 'like', "%{$kw}%")
                            ->orWhere('pickup_address', 'like', "%{$kw}%")
                            ->orWhere('dropoff_address', 'like', "%{$kw}%")
                            ->orWhere('pickup_contact_name', 'like', "%{$kw}%")
                            ->orWhere('dropoff_contact_name', 'like', "%{$kw}%");
                    });
                })
                ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($payload['audit_status'] ?? null, fn ($query, $auditStatus) => $query->where('audit_status', $auditStatus))
                ->when(array_key_exists('is_locked', $payload), fn ($query) => $query->where('is_locked', (bool) $payload['is_locked']))
                ->when($payload['cargo_category_id'] ?? null, fn ($query, $cargoCategoryId) => $query->where('cargo_category_id', (int) $cargoCategoryId))
                ->when($payload['pickup_site_id'] ?? null, fn ($query, $pickupSiteId) => $query->where('pickup_site_id', (int) $pickupSiteId))
                ->when($payload['dropoff_site_id'] ?? null, fn ($query, $dropoffSiteId) => $query->where('dropoff_site_id', (int) $dropoffSiteId))
                ->when($payload['trace_type'] ?? null, function ($query, $traceType): void {
                    if ($traceType === 'split') {
                        $query->whereNotNull('meta->split_from_id');
                        return;
                    }
                    if ($traceType === 'merge') {
                        $query->whereNotNull('meta->merge_from_ids');
                        return;
                    }
                    $query->whereNull('meta->split_from_id')
                        ->whereNull('meta->merge_from_ids');
                })
                ->when($payload['expected_pickup_from'] ?? null, fn ($query, $from) => $query->where('expected_pickup_at', '>=', $from))
                ->when($payload['expected_pickup_to'] ?? null, fn ($query, $to) => $query->where('expected_pickup_at', '<=', $to))
                ->latest()
                ->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'cargo_category_id' => ['required', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'pickup_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_contact_name' => ['nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['nullable', 'string', 'max:32'],
            'dropoff_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_contact_name' => ['nullable', 'string', 'max:64'],
            'dropoff_contact_phone' => ['nullable', 'string', 'max:32'],
            'cargo_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'cargo_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'freight_calc_scheme' => ['nullable', 'in:by_weight,by_volume,by_trip'],
            'freight_unit_price' => ['nullable', 'numeric', 'min:0', 'required_with:freight_calc_scheme'],
            'freight_trip_count' => ['nullable', 'integer', 'min:1'],
            'actual_delivered_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'loss_allowance_kg' => ['nullable', 'numeric', 'min:0'],
            'loss_deduct_unit_price' => ['nullable', 'numeric', 'min:0'],
            'expected_pickup_at' => ['nullable', 'date'],
            'expected_delivery_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $payload['order_no'] = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        $payload['submitter_id'] = (int) $request->user()->id;
        $payload['audit_status'] = 'approved';
        $payload['audited_by'] = (int) $request->user()->id;
        $payload['audited_at'] = now();
        $payload = $this->prepareOrderPayload($payload);
        if (! $this->dataScopeService->canAccessSites($request->user(), [$payload['pickup_site_id'] ?? null, $payload['dropoff_site_id'] ?? null])) {
            return response()->json(['message' => '当前账号不可创建该范围内的计划单'], 403);
        }
        $payload = $this->applyFreightTemplateToPayload($payload, $request->user());

        $order = PrePlanOrder::query()->create($payload);
        $this->appendOrderHistory($order, 'dispatcher_create', $request);
        $order->save();

        return response()->json($order, 201);
    }

    public function batchStore(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'orders' => ['required', 'array', 'min:1', 'max:200'],
            'orders.*.cargo_category_id' => ['required', 'integer', 'exists:cargo_categories,id'],
            'orders.*.client_name' => ['required', 'string', 'max:255'],
            'orders.*.pickup_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'orders.*.pickup_address' => ['required', 'string', 'max:255'],
            'orders.*.pickup_contact_name' => ['nullable', 'string', 'max:64'],
            'orders.*.pickup_contact_phone' => ['nullable', 'string', 'max:32'],
            'orders.*.dropoff_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'orders.*.dropoff_address' => ['required', 'string', 'max:255'],
            'orders.*.dropoff_contact_name' => ['nullable', 'string', 'max:64'],
            'orders.*.dropoff_contact_phone' => ['nullable', 'string', 'max:32'],
            'orders.*.cargo_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'orders.*.cargo_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'orders.*.freight_calc_scheme' => ['nullable', 'in:by_weight,by_volume,by_trip'],
            'orders.*.freight_unit_price' => ['nullable', 'numeric', 'min:0'],
            'orders.*.freight_trip_count' => ['nullable', 'integer', 'min:1'],
            'orders.*.actual_delivered_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'orders.*.loss_allowance_kg' => ['nullable', 'numeric', 'min:0'],
            'orders.*.loss_deduct_unit_price' => ['nullable', 'numeric', 'min:0'],
            'orders.*.expected_pickup_at' => ['nullable', 'date'],
            'orders.*.expected_delivery_at' => ['nullable', 'date'],
            'orders.*.meta' => ['nullable', 'array'],
        ]);

        $createdOrders = DB::transaction(function () use ($payload, $request) {
            return collect($payload['orders'])->map(function (array $orderPayload) use ($request) {
                $orderPayload['order_no'] = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
                $orderPayload['submitter_id'] = (int) $request->user()->id;
                $orderPayload['audit_status'] = 'approved';
                $orderPayload['audited_by'] = (int) $request->user()->id;
                $orderPayload['audited_at'] = now();
                $orderPayload = $this->prepareOrderPayload($orderPayload);
                if (! $this->dataScopeService->canAccessSites($request->user(), [$orderPayload['pickup_site_id'] ?? null, $orderPayload['dropoff_site_id'] ?? null])) {
                    abort(403, '当前账号不可创建该范围内的计划单');
                }
                $orderPayload = $this->applyFreightTemplateToPayload($orderPayload, $request->user());

                $order = PrePlanOrder::query()->create($orderPayload);
                $this->appendOrderHistory($order, 'dispatcher_batch_create', $request);
                $order->save();

                return $order;
            });
        });

        return response()->json([
            'count' => $createdOrders->count(),
            'data' => $createdOrders->values(),
        ], 201);
    }

    public function import(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        /** @var UploadedFile $file */
        $file = $payload['file'];
        $rows = $this->readCsvRows($file);

        if ($rows === []) {
            return response()->json([
                'message' => '导入文件为空或无有效数据',
                'created_count' => 0,
                'failed_count' => 0,
                'errors' => [],
            ]);
        }

        $created = [];
        $errors = [];
        $operatorId = (int) $request->user()->id;

        foreach ($rows as $line => $row) {
            try {
                $cargoCategoryId = $this->resolveCargoCategoryId($row);
                if (! $cargoCategoryId) {
                    $errors[] = ['line' => $line, 'message' => '无法匹配货品分类，请提供有效分类ID/编码/名称'];
                    continue;
                }

                $clientName = trim((string) ($row['client_name'] ?? ''));
                $pickupAddress = trim((string) ($row['pickup_address'] ?? ''));
                $dropoffAddress = trim((string) ($row['dropoff_address'] ?? ''));
                if ($clientName === '' || $pickupAddress === '' || $dropoffAddress === '') {
                    $errors[] = ['line' => $line, 'message' => 'client_name、pickup_address、dropoff_address 为必填'];
                    continue;
                }

                $orderPayload = [
                    'order_no' => 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'cargo_category_id' => $cargoCategoryId,
                    'submitter_id' => $operatorId,
                    'client_name' => $clientName,
                    'pickup_address' => $pickupAddress,
                    'pickup_contact_name' => $this->nullableString($row['pickup_contact_name'] ?? null),
                    'pickup_contact_phone' => $this->nullableString($row['pickup_contact_phone'] ?? null),
                    'dropoff_address' => $dropoffAddress,
                    'dropoff_contact_name' => $this->nullableString($row['dropoff_contact_name'] ?? null),
                    'dropoff_contact_phone' => $this->nullableString($row['dropoff_contact_phone'] ?? null),
                    'cargo_weight_kg' => $this->nullableNumber($row['cargo_weight_kg'] ?? null),
                    'cargo_volume_m3' => $this->nullableNumber($row['cargo_volume_m3'] ?? null),
                    'freight_calc_scheme' => $this->nullableString($row['freight_calc_scheme'] ?? null),
                    'freight_unit_price' => $this->nullableNumber($row['freight_unit_price'] ?? null),
                    'freight_trip_count' => $this->nullableInt($row['freight_trip_count'] ?? null),
                    'actual_delivered_weight_kg' => $this->nullableNumber($row['actual_delivered_weight_kg'] ?? null),
                    'loss_allowance_kg' => $this->nullableNumber($row['loss_allowance_kg'] ?? null) ?? 0,
                    'loss_deduct_unit_price' => $this->nullableNumber($row['loss_deduct_unit_price'] ?? null),
                    'expected_pickup_at' => $this->nullableString($row['expected_pickup_at'] ?? null),
                    'expected_delivery_at' => $this->nullableString($row['expected_delivery_at'] ?? null),
                    'audit_status' => 'approved',
                    'audited_by' => $operatorId,
                    'audited_at' => now(),
                ];

                if (! in_array($orderPayload['freight_calc_scheme'], ['by_weight', 'by_volume', 'by_trip'], true)) {
                    $orderPayload['freight_calc_scheme'] = null;
                    $orderPayload['freight_unit_price'] = null;
                    $orderPayload['freight_trip_count'] = 1;
                }
                if ($orderPayload['freight_calc_scheme'] !== 'by_trip') {
                    $orderPayload['freight_trip_count'] = 1;
                }
                $orderPayload = $this->prepareOrderPayload($orderPayload);
                if (! $this->dataScopeService->canAccessSites($request->user(), [$orderPayload['pickup_site_id'] ?? null, $orderPayload['dropoff_site_id'] ?? null])) {
                    $errors[] = ['line' => $line, 'message' => '当前账号不可导入该范围内的计划单'];
                    continue;
                }
                $orderPayload = $this->applyFreightTemplateToPayload($orderPayload, $request->user());

                $created[] = PrePlanOrder::query()->create($orderPayload);
            } catch (Throwable $e) {
                $errors[] = ['line' => $line, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'created_count' => count($created),
            'failed_count' => count($errors),
            'errors' => $errors,
            'data' => collect($created)->values(),
        ]);
    }

    public function customerSubmit(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'cargo_category_id' => ['required', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'pickup_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_contact_name' => ['nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['nullable', 'string', 'max:32'],
            'dropoff_site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_contact_name' => ['nullable', 'string', 'max:64'],
            'dropoff_contact_phone' => ['nullable', 'string', 'max:32'],
            'cargo_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'cargo_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'freight_calc_scheme' => ['nullable', 'in:by_weight,by_volume,by_trip'],
            'freight_unit_price' => ['nullable', 'numeric', 'min:0', 'required_with:freight_calc_scheme'],
            'freight_trip_count' => ['nullable', 'integer', 'min:1'],
            'actual_delivered_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'loss_allowance_kg' => ['nullable', 'numeric', 'min:0'],
            'loss_deduct_unit_price' => ['nullable', 'numeric', 'min:0'],
            'expected_pickup_at' => ['nullable', 'date'],
            'expected_delivery_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $payload['order_no'] = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        $payload['submitter_id'] = (int) $request->user()->id;
        $payload['audit_status'] = 'pending_approval';
        $payload['audited_by'] = null;
        $payload['audited_at'] = null;
        $payload['audit_remark'] = null;
        $payload['status'] = 'pending';
        $payload = $this->prepareOrderPayload($payload);
        $payload = $this->applyFreightTemplateToPayload($payload, $request->user());

        $order = PrePlanOrder::query()->create($payload);
        $this->appendOrderHistory($order, 'customer_submit', $request);
        $order->save();

        return response()->json($order, 201);
    }

    public function customerList(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'audit_status' => ['nullable', 'in:pending_approval,approved,rejected'],
        ]);

        return response()->json(
            $this->scopedOrderQuery($request)
                ->where('submitter_id', (int) $request->user()->id)
                ->when($payload['audit_status'] ?? null, fn ($query, $auditStatus) => $query->where('audit_status', $auditStatus))
                ->latest()
                ->paginate(20)
        );
    }

    public function customerDetail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        $order = $this->scopedOrderQuery($request)->findOrFail($payload['id']);
        if ((int) $order->submitter_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($order);
    }

    public function customerUpdate(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'cargo_category_id' => ['sometimes', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['sometimes', 'string', 'max:255'],
            'pickup_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'dropoff_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_address' => ['sometimes', 'string', 'max:255'],
            'dropoff_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'dropoff_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'cargo_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'cargo_volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'freight_calc_scheme' => ['sometimes', 'nullable', 'in:by_weight,by_volume,by_trip'],
            'freight_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'freight_trip_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'actual_delivered_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'loss_allowance_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'loss_deduct_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expected_pickup_at' => ['sometimes', 'nullable', 'date'],
            'expected_delivery_at' => ['sometimes', 'nullable', 'date'],
            'meta' => ['sometimes', 'array'],
        ]);

        $order = $this->scopedOrderQuery($request)->findOrFail($payload['id']);
        if ((int) $order->submitter_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($order->audit_status !== 'rejected') {
            return response()->json(['message' => '仅已驳回计划单可由客户修改'], 422);
        }
        if (! $this->canPerformOrderAction($order, 'edit')) {
            return response()->json(['message' => '当前状态下预计划单不可修改'], 422);
        }

        unset($payload['id']);
        $payload = $this->prepareOrderPayload($payload, $order);
        $payload = $this->refreshFreightTemplatePayload($payload, $order, $request->user());
        $order->fill($payload);
        $this->appendOrderHistory($order, 'customer_update', $request, [
            'updated_fields' => array_values(array_keys($payload)),
        ]);
        $order->save();

        return response()->json($order->fresh());
    }

    public function customerResubmit(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        return DB::transaction(function () use ($payload, $request): JsonResponse {
            $order = $this->scopedOrderQuery($request)->lockForUpdate()->findOrFail($payload['id']);
            if ((int) $order->submitter_id !== (int) $request->user()->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if ($order->audit_status !== 'rejected') {
                return response()->json(['message' => '仅已驳回计划单可重新提报'], 422);
            }
            if (! $this->canModifyOrder($order)) {
                return response()->json(['message' => '关联任务节点已到达/完成，预计划单不可修改'], 422);
            }

            $order->audit_status = 'pending_approval';
            $order->audited_by = null;
            $order->audited_at = null;
            $order->audit_remark = null;
            $this->appendOrderHistory($order, 'customer_resubmit', $request);
            $order->save();

            return response()->json($order);
        });
    }

    public function auditList(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'audit_status' => ['nullable', 'in:pending_approval,approved,rejected'],
            'submitter_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_locked' => ['nullable', 'boolean'],
        ]);

        return response()->json(
            $this->scopedOrderQuery($request)
                ->when($payload['audit_status'] ?? null, fn ($query, $auditStatus) => $query->where('audit_status', $auditStatus))
                ->when($payload['submitter_id'] ?? null, fn ($query, $submitterId) => $query->where('submitter_id', $submitterId))
                ->when(array_key_exists('is_locked', $payload), fn ($query) => $query->where('is_locked', (bool) $payload['is_locked']))
                ->latest()
                ->paginate(20)
        );
    }

    public function lock(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        $order = $this->scopedOrderQuery($request)->findOrFail($payload['id']);
        if (! $this->canPerformOrderAction($order, 'lock')) {
            return response()->json(['message' => '当前状态下预计划单不可锁定'], 422);
        }

        $order->is_locked = true;
        $this->appendOrderHistory($order, 'dispatcher_lock', $request);
        $order->save();

        return response()->json($order->fresh());
    }

    public function unlock(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        $order = $this->scopedOrderQuery($request)->findOrFail($payload['id']);
        if (! $this->canPerformOrderAction($order, 'unlock')) {
            return response()->json(['message' => '当前状态下预计划单不可解锁'], 422);
        }

        $order->is_locked = false;
        $this->appendOrderHistory($order, 'dispatcher_unlock', $request);
        $order->save();

        return response()->json($order->fresh());
    }

    public function void(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'void_remark' => ['required', 'string', 'max:255'],
        ]);

        $order = $this->scopedOrderQuery($request)->findOrFail($payload['id']);
        if (! $this->canPerformOrderAction($order, 'void')) {
            return response()->json(['message' => '当前状态下预计划单不可作废'], 422);
        }

        $order->status = 'cancelled';
        $order->voided_by = (int) $request->user()->id;
        $order->voided_at = now();
        $order->void_remark = $payload['void_remark'];
        $this->appendOrderHistory($order, 'dispatcher_void', $request, [
            'void_remark' => $payload['void_remark'],
        ]);
        $order->save();

        return response()->json($order->fresh());
    }

    public function split(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'parts' => ['required', 'array', 'min:2', 'max:20'],
            'parts.*.cargo_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'parts.*.cargo_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'parts.*.expected_pickup_at' => ['nullable', 'date'],
            'parts.*.expected_delivery_at' => ['nullable', 'date'],
        ]);

        $result = DB::transaction(function () use ($payload, $request): array {
            $source = $this->scopedOrderQuery($request)->lockForUpdate()->findOrFail($payload['id']);
            if (! $this->canSplitOrMerge($source)) {
                abort(422, '当前计划单不允许拆单（需为待调度、未锁定、未执行且未作废）');
            }

            $sourceWeight = (float) ($source->cargo_weight_kg ?? 0);
            $sourceVolume = (float) ($source->cargo_volume_m3 ?? 0);
            $sumWeight = collect($payload['parts'])->sum(fn ($item) => (float) ($item['cargo_weight_kg'] ?? 0));
            $sumVolume = collect($payload['parts'])->sum(fn ($item) => (float) ($item['cargo_volume_m3'] ?? 0));

            if ($sourceWeight > 0 && abs($sumWeight - $sourceWeight) > 0.01) {
                abort(422, '拆单后重量合计必须等于原计划单重量');
            }
            if ($sourceVolume > 0 && abs($sumVolume - $sourceVolume) > 0.01) {
                abort(422, '拆单后体积合计必须等于原计划单体积');
            }

            $created = [];
            foreach ($payload['parts'] as $index => $part) {
                $newPayload = $source->toArray();
                unset(
                    $newPayload['id'],
                    $newPayload['order_no'],
                    $newPayload['created_at'],
                    $newPayload['updated_at'],
                    $newPayload['voided_by'],
                    $newPayload['voided_at'],
                    $newPayload['void_remark']
                );
                $newPayload['order_no'] = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
                $newPayload['cargo_weight_kg'] = $part['cargo_weight_kg'] ?? 0;
                $newPayload['cargo_volume_m3'] = $part['cargo_volume_m3'] ?? 0;
                $newPayload['expected_pickup_at'] = $part['expected_pickup_at'] ?? $source->expected_pickup_at;
                $newPayload['expected_delivery_at'] = $part['expected_delivery_at'] ?? $source->expected_delivery_at;
                $newPayload['meta'] = array_merge(
                    is_array($source->meta) ? $source->meta : [],
                    [
                        'split_from_id' => (int) $source->id,
                        'split_part_no' => $index + 1,
                    ]
                );

                $newOrder = PrePlanOrder::query()->create($newPayload);
                $this->appendOrderHistory($newOrder, 'dispatcher_split_create', $request, [
                    'split_from_id' => (int) $source->id,
                    'split_part_no' => $index + 1,
                ]);
                $newOrder->save();

                $created[] = $newOrder;
            }

            $source->status = 'cancelled';
            $source->voided_by = (int) $request->user()->id;
            $source->voided_at = now();
            $source->void_remark = '拆单后原单自动作废';
            $this->appendOrderHistory($source, 'dispatcher_split_source_voided', $request, [
                'split_created_count' => count($created),
            ]);
            $source->save();

            return [
                'source' => $source->fresh(),
                'created' => collect($created)->values(),
            ];
        });

        return response()->json($result, 201);
    }

    public function merge(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:2', 'max:20'],
            'ids.*' => ['integer', 'exists:pre_plan_orders,id'],
        ]);

        $result = DB::transaction(function () use ($payload, $request): array {
            $ids = collect($payload['ids'])->map(fn ($id) => (int) $id)->unique()->values();
            $orders = $this->scopedOrderQuery($request)
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== $ids->count()) {
                abort(422, '存在无效计划单，无法并单');
            }

            foreach ($orders as $order) {
                if (! $this->canSplitOrMerge($order)) {
                    abort(422, '所选计划单存在不可并单数据（需为待调度、未锁定、未执行且未作废）');
                }
            }

            $base = $orders->first();
            $mergeKeys = [
                'cargo_category_id',
                'client_name',
                'pickup_address',
                'dropoff_address',
                'pickup_contact_name',
                'pickup_contact_phone',
                'dropoff_contact_name',
                'dropoff_contact_phone',
                'submitter_id',
                'audit_status',
            ];
            foreach ($orders as $order) {
                foreach ($mergeKeys as $key) {
                    if ((string) ($order->{$key} ?? '') !== (string) ($base->{$key} ?? '')) {
                        abort(422, '并单要求客户、货品、装卸地、联系人及审核状态一致');
                    }
                }
            }

            $newPayload = $base->toArray();
            unset(
                $newPayload['id'],
                $newPayload['order_no'],
                $newPayload['created_at'],
                $newPayload['updated_at'],
                $newPayload['voided_by'],
                $newPayload['voided_at'],
                $newPayload['void_remark']
            );
            $newPayload['order_no'] = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
            $newPayload['cargo_weight_kg'] = $orders->sum(fn ($item) => (float) ($item->cargo_weight_kg ?? 0));
            $newPayload['cargo_volume_m3'] = $orders->sum(fn ($item) => (float) ($item->cargo_volume_m3 ?? 0));
            if ($base->freight_calc_scheme === 'by_trip') {
                $newPayload['freight_trip_count'] = (int) $orders->sum(fn ($item) => (int) ($item->freight_trip_count ?? 1));
            }
            $newPayload['meta'] = array_merge(
                is_array($base->meta) ? $base->meta : [],
                [
                    'merge_from_ids' => $ids->all(),
                ]
            );

            $merged = PrePlanOrder::query()->create($newPayload);
            $this->appendOrderHistory($merged, 'dispatcher_merge_create', $request, [
                'merge_from_ids' => $ids->all(),
            ]);
            $merged->save();

            foreach ($orders as $order) {
                $order->status = 'cancelled';
                $order->voided_by = (int) $request->user()->id;
                $order->voided_at = now();
                $order->void_remark = sprintf('并单生成新计划单 %s 后自动作废', $merged->order_no);
                $this->appendOrderHistory($order, 'dispatcher_merge_source_voided', $request, [
                    'merged_order_id' => (int) $merged->id,
                    'merged_order_no' => (string) $merged->order_no,
                ]);
                $order->save();
            }

            return [
                'merged' => $merged,
                'merged_from_ids' => $ids->all(),
            ];
        });

        return response()->json($result, 201);
    }

    public function auditApprove(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'audit_remark' => ['nullable', 'string', 'max:255'],
        ]);

        $order = DB::transaction(function () use ($payload, $request): PrePlanOrder {
            $order = $this->scopedOrderQuery($request)->lockForUpdate()->findOrFail($payload['id']);
            if ($order->audit_status !== 'pending_approval') {
                abort(422, '仅待审核计划单可执行审核通过');
            }

            $order->audit_status = 'approved';
            $order->audited_by = (int) $request->user()->id;
            $order->audited_at = now();
            $order->audit_remark = $payload['audit_remark'] ?? null;
            $this->appendOrderHistory($order, 'dispatcher_audit_approve', $request, [
                'audit_remark' => $payload['audit_remark'] ?? null,
            ]);
            $order->save();

            if ($order->submitter_id) {
                SystemMessage::query()->create([
                    'user_id' => (int) $order->submitter_id,
                    'message_type' => 'audit_notice',
                    'title' => '计划单审核通过',
                    'content' => sprintf(
                        '计划单 %s 已审核通过%s',
                        $order->order_no,
                        $order->audit_remark ? '，备注：'.$order->audit_remark : ''
                    ),
                    'meta' => [
                        'order_id' => (int) $order->id,
                        'order_no' => (string) $order->order_no,
                        'audit_status' => 'approved',
                    ],
                ]);
            }

            return $order;
        });

        return response()->json($order);
    }

    public function auditBatchApprove(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:pre_plan_orders,id'],
            'audit_remark' => ['nullable', 'string', 'max:255'],
        ]);

        $approvedCount = DB::transaction(function () use ($payload, $request): int {
            $ids = collect($payload['ids'])->map(fn ($id) => (int) $id)->unique()->values();
            $orders = $this->scopedOrderQuery($request)->whereIn('id', $ids->all())->lockForUpdate()->get();
            $count = 0;
            foreach ($orders as $order) {
                if ($order->audit_status !== 'pending_approval') {
                    continue;
                }
                $order->audit_status = 'approved';
                $order->audited_by = (int) $request->user()->id;
                $order->audited_at = now();
                $order->audit_remark = $payload['audit_remark'] ?? null;
                $this->appendOrderHistory($order, 'dispatcher_audit_batch_approve', $request, [
                    'audit_remark' => $payload['audit_remark'] ?? null,
                ]);
                $order->save();
                $count++;
            }
            return $count;
        });

        return response()->json(['approved_count' => $approvedCount]);
    }

    public function auditReject(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'audit_remark' => ['required', 'string', 'max:255'],
        ]);

        $order = DB::transaction(function () use ($payload, $request): PrePlanOrder {
            $order = $this->scopedOrderQuery($request)->lockForUpdate()->findOrFail($payload['id']);
            if ($order->audit_status !== 'pending_approval') {
                abort(422, '仅待审核计划单可执行驳回');
            }

            $order->audit_status = 'rejected';
            $order->audited_by = (int) $request->user()->id;
            $order->audited_at = now();
            $order->audit_remark = $payload['audit_remark'];
            $meta = is_array($order->meta) ? $order->meta : [];
            $meta['rejected_snapshot'] = $this->buildRevisionSnapshot($order);
            $meta['rejected_at'] = now()->toDateTimeString();
            $order->meta = $meta;
            $this->appendOrderHistory($order, 'dispatcher_audit_reject', $request, [
                'audit_remark' => $payload['audit_remark'],
            ]);
            $order->save();

            if ($order->submitter_id) {
                SystemMessage::query()->create([
                    'user_id' => (int) $order->submitter_id,
                    'message_type' => 'audit_notice',
                    'title' => '计划单审核驳回',
                    'content' => sprintf('计划单 %s 被驳回，原因：%s', $order->order_no, $order->audit_remark),
                    'meta' => [
                        'order_id' => (int) $order->id,
                        'order_no' => (string) $order->order_no,
                        'audit_status' => 'rejected',
                    ],
                ]);
            }

            return $order;
        });

        return response()->json($order);
    }

    public function auditBatchReject(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:pre_plan_orders,id'],
            'audit_remark' => ['required', 'string', 'max:255'],
        ]);

        $rejectedCount = DB::transaction(function () use ($payload, $request): int {
            $ids = collect($payload['ids'])->map(fn ($id) => (int) $id)->unique()->values();
            $orders = $this->scopedOrderQuery($request)->whereIn('id', $ids->all())->lockForUpdate()->get();
            $count = 0;
            foreach ($orders as $order) {
                if ($order->audit_status !== 'pending_approval') {
                    continue;
                }
                $order->audit_status = 'rejected';
                $order->audited_by = (int) $request->user()->id;
                $order->audited_at = now();
                $order->audit_remark = $payload['audit_remark'];
                $meta = is_array($order->meta) ? $order->meta : [];
                $meta['rejected_snapshot'] = $this->buildRevisionSnapshot($order);
                $meta['rejected_at'] = now()->toDateTimeString();
                $order->meta = $meta;
                $this->appendOrderHistory($order, 'dispatcher_audit_batch_reject', $request, [
                    'audit_remark' => $payload['audit_remark'],
                ]);
                $order->save();
                $count++;
            }
            return $count;
        });

        return response()->json(['rejected_count' => $rejectedCount]);
    }

    public function auditRemarkTemplates(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['code' => 'ok_basic', 'text' => '资料完整，审核通过'],
                ['code' => 'ok_schedule', 'text' => '时效与运力匹配，审核通过'],
                ['code' => 'reject_contact', 'text' => '联系人或联系方式缺失，请补充后重提'],
                ['code' => 'reject_address', 'text' => '装卸地址信息不完整，请补充后重提'],
                ['code' => 'reject_price', 'text' => '运费规则不完整，请补充后重提'],
            ],
        ]);
    }

    public function auditTimeoutReminder(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'timeout_hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ]);

        $timeoutHours = (int) ($payload['timeout_hours'] ?? 4);
        $orders = $this->scopedOrderQuery($request)
            ->where('audit_status', 'pending_approval')
            ->where('created_at', '<=', now()->subHours($timeoutHours))
            ->get(['id', 'order_no', 'created_at']);

        $targets = \App\Models\User::query()
            ->whereIn('role', ['admin', 'dispatcher'])
            ->where('status', 'active')
            ->get(['id']);

        foreach ($targets as $target) {
            SystemMessage::query()->create([
                'user_id' => (int) $target->id,
                'message_type' => 'audit_reminder',
                'title' => '待审核计划单超时提醒',
                'content' => sprintf('当前有 %d 条计划单超过 %d 小时未审核，请及时处理', $orders->count(), $timeoutHours),
                'meta' => [
                    'timeout_hours' => $timeoutHours,
                    'order_ids' => $orders->pluck('id')->values()->all(),
                ],
            ]);
        }

        return response()->json([
            'timeout_hours' => $timeoutHours,
            'timeout_count' => $orders->count(),
            'notified_user_count' => $targets->count(),
        ]);
    }

    public function revisionCompare(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        $order = $this->scopedOrderQuery($request)->findOrFail((int) $payload['id']);
        $snapshot = data_get($order->meta, 'rejected_snapshot');
        if (! is_array($snapshot)) {
            return response()->json([
                'order_id' => (int) $order->id,
                'has_snapshot' => false,
                'diffs' => [],
            ]);
        }
        $current = $this->buildRevisionSnapshot($order);
        $diffs = collect(array_keys($snapshot))
            ->filter(fn ($key) => (string) ($snapshot[$key] ?? '') !== (string) ($current[$key] ?? ''))
            ->map(fn ($key) => [
                'field' => $key,
                'before' => $snapshot[$key] ?? null,
                'after' => $current[$key] ?? null,
            ])
            ->values()
            ->all();

        return response()->json([
            'order_id' => (int) $order->id,
            'has_snapshot' => true,
            'diffs' => $diffs,
        ]);
    }

    public function auditLogList(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'operator_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $keyword = trim((string) ($payload['keyword'] ?? ''));
        $action = trim((string) ($payload['action'] ?? ''));
        $operatorId = $payload['operator_id'] ?? null;
        $page = (int) ($payload['page'] ?? 1);
        $pageSize = (int) ($payload['page_size'] ?? 20);

        $orders = $this->scopedOrderQuery($request)
            ->when($keyword !== '', fn ($query) => $query->where(function ($sub) use ($keyword): void {
                $sub->where('order_no', 'like', "%{$keyword}%")
                    ->orWhere('client_name', 'like', "%{$keyword}%");
            }))
            ->latest()
            ->limit(1000)
            ->get(['id', 'order_no', 'client_name', 'meta']);

        $logs = $orders->flatMap(function (PrePlanOrder $order) {
            $history = data_get($order->meta, 'history');
            if (! is_array($history)) {
                return [];
            }

            return collect($history)->map(function ($row) use ($order) {
                return [
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'client_name' => (string) $order->client_name,
                    'at' => $row['at'] ?? null,
                    'action' => $row['action'] ?? null,
                    'operator_id' => $row['operator_id'] ?? null,
                    'operator_account' => $row['operator_account'] ?? null,
                    'operator_name' => $row['operator_name'] ?? null,
                    'extra' => $row['extra'] ?? null,
                ];
            });
        })->filter(function (array $row) use ($action, $operatorId): bool {
            if ($action !== '' && (string) ($row['action'] ?? '') !== $action) {
                return false;
            }
            if ($operatorId !== null && (int) ($row['operator_id'] ?? 0) !== (int) $operatorId) {
                return false;
            }
            return true;
        })->sortByDesc('at')->values();

        $total = $logs->count();
        $data = $logs->slice(($page - 1) * $pageSize, $pageSize)->values()->all();

        return response()->json([
            'data' => $data,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $pageSize,
        ]);
    }

    public function show(PrePlanOrder $prePlanOrder): JsonResponse
    {
        abort_unless($this->scopedOrderQuery(request())->whereKey($prePlanOrder->id)->exists(), 404);

        return response()->json($prePlanOrder);
    }

    public function showByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        $prePlanOrder = $this->scopedOrderQuery($request)->findOrFail($payload['id']);

        return response()->json($prePlanOrder);
    }

    public function update(Request $request, PrePlanOrder $prePlanOrder): JsonResponse
    {
        if (! $this->canPerformOrderAction($prePlanOrder, 'edit')) {
            return response()->json(['message' => '当前状态下预计划单不可修改'], 422);
        }

        $payload = $request->validate([
            'cargo_category_id' => ['sometimes', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['sometimes', 'string', 'max:255'],
            'pickup_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'dropoff_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_address' => ['sometimes', 'string', 'max:255'],
            'dropoff_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'dropoff_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'cargo_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'cargo_volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'freight_calc_scheme' => ['sometimes', 'nullable', 'in:by_weight,by_volume,by_trip'],
            'freight_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'freight_trip_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'actual_delivered_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'loss_allowance_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'loss_deduct_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expected_pickup_at' => ['sometimes', 'date'],
            'expected_delivery_at' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:pending,scheduled,in_progress,completed,cancelled'],
            'meta' => ['sometimes', 'array'],
        ]);

        $payload = $this->prepareOrderPayload($payload, $prePlanOrder);
        $prePlanOrder->update($payload);

        return response()->json($prePlanOrder->fresh());
    }

    public function updateByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'cargo_category_id' => ['sometimes', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['sometimes', 'string', 'max:255'],
            'pickup_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'dropoff_site_id' => ['sometimes', 'nullable', 'integer', 'exists:logistics_sites,id'],
            'dropoff_address' => ['sometimes', 'string', 'max:255'],
            'dropoff_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'dropoff_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'cargo_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'cargo_volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'freight_calc_scheme' => ['sometimes', 'nullable', 'in:by_weight,by_volume,by_trip'],
            'freight_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'freight_trip_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'actual_delivered_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'loss_allowance_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'loss_deduct_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expected_pickup_at' => ['sometimes', 'date'],
            'expected_delivery_at' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:pending,scheduled,in_progress,completed,cancelled'],
            'meta' => ['sometimes', 'array'],
        ]);

        $prePlanOrder = $this->scopedOrderQuery($request)->findOrFail($payload['id']);
        if (! $this->canPerformOrderAction($prePlanOrder, 'edit')) {
            return response()->json(['message' => '当前状态下预计划单不可修改'], 422);
        }
        unset($payload['id']);
        $payload = $this->prepareOrderPayload($payload, $prePlanOrder);
        $prePlanOrder->fill($payload);
        $this->appendOrderHistory($prePlanOrder, 'dispatcher_update', $request, [
            'updated_fields' => array_values(array_keys($payload)),
        ]);
        $prePlanOrder->save();

        return response()->json($prePlanOrder->fresh());
    }

    private function scopedOrderQuery(Request $request): Builder
    {
        return $this->dataScopeService->applyPrePlanOrderScope(
            PrePlanOrder::query(),
            $request->user()
        );
    }

    private function prepareOrderPayload(array $payload, ?PrePlanOrder $current = null): array
    {
        $payload = $this->hydrateSiteReference($payload, 'pickup_site_id', 'pickup_address', $current?->pickup_site_id, $current?->pickup_address);
        $payload = $this->hydrateSiteReference($payload, 'dropoff_site_id', 'dropoff_address', $current?->dropoff_site_id, $current?->dropoff_address);

        return $payload;
    }

    private function hydrateSiteReference(
        array $payload,
        string $siteIdKey,
        string $addressKey,
        ?int $currentSiteId = null,
        ?string $currentAddress = null,
    ): array {
        $siteIdProvided = array_key_exists($siteIdKey, $payload);
        $addressProvided = array_key_exists($addressKey, $payload);
        $siteId = $siteIdProvided ? (int) ($payload[$siteIdKey] ?? 0) : $currentSiteId;
        $address = $addressProvided ? trim((string) ($payload[$addressKey] ?? '')) : (string) ($currentAddress ?? '');

        if ($siteIdProvided && ($payload[$siteIdKey] === null || (int) $payload[$siteIdKey] <= 0)) {
            $payload[$siteIdKey] = null;

            return $payload;
        }

        if ($siteId > 0) {
            $site = LogisticsSite::query()->find($siteId);
            if ($site) {
                $payload[$siteIdKey] = (int) $site->id;
                if (! $addressProvided || $address === '') {
                    $payload[$addressKey] = (string) $site->address;
                }
            }

            return $payload;
        }

        if (! $addressProvided || $address === '') {
            return $payload;
        }

        $matchedSiteId = LogisticsSite::query()
            ->where(function (Builder $query) use ($address): void {
                $query->where('address', $address)
                    ->orWhere('name', $address);
            })
            ->value('id');

        $payload[$siteIdKey] = $matchedSiteId ? (int) $matchedSiteId : null;

        return $payload;
    }

    private function canModifyOrder(PrePlanOrder $prePlanOrder, bool $ignoreLock = false): bool
    {
        if (! $ignoreLock && (bool) $prePlanOrder->is_locked) {
            return false;
        }

        return ! $prePlanOrder->dispatchTasks()
            ->whereHas('waypoints', fn ($query) => $query->whereIn('status', ['arrived', 'completed']))
            ->exists();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function readCsvRows(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        if (! $realPath) {
            return [];
        }

        $handle = fopen($realPath, 'rb');
        if (! $handle) {
            return [];
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);
            return [];
        }

        $keys = array_map(fn ($item) => $this->normalizeHeader((string) $item), $header);
        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            $row = [];
            foreach ($keys as $index => $key) {
                if ($key === '') {
                    continue;
                }
                $row[$key] = array_key_exists($index, $data) ? trim((string) $data[$index]) : null;
            }
            $hasValue = collect($row)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
            if ($hasValue) {
                $rows[$line] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $value = trim(mb_strtolower($header));

        return match ($value) {
            'cargo_category_id', '货品分类id', '货品id' => 'cargo_category_id',
            'cargo_category_code', '货品分类编码', '分类编码', 'cargo_code' => 'cargo_category_code',
            'cargo_category_name', '货品分类名称', '货品分类', '分类名称' => 'cargo_category_name',
            'client_name', '客户名称', '客户' => 'client_name',
            'pickup_address', '装货地', '装货地址' => 'pickup_address',
            'pickup_contact_name', '装货联系人' => 'pickup_contact_name',
            'pickup_contact_phone', '装货联系电话' => 'pickup_contact_phone',
            'dropoff_address', '卸货地', '收货地址', '卸货地址' => 'dropoff_address',
            'dropoff_contact_name', '收货联系人', '卸货联系人' => 'dropoff_contact_name',
            'dropoff_contact_phone', '收货联系电话', '卸货联系电话' => 'dropoff_contact_phone',
            'cargo_weight_kg', '重量kg', '重量', '货重' => 'cargo_weight_kg',
            'cargo_volume_m3', '体积m3', '体积' => 'cargo_volume_m3',
            'freight_calc_scheme', '运费计算方式', '运价方式' => 'freight_calc_scheme',
            'freight_unit_price', '运费单价', '运价单价' => 'freight_unit_price',
            'freight_trip_count', '趟数' => 'freight_trip_count',
            'actual_delivered_weight_kg', '实送重量kg', '实送重量' => 'actual_delivered_weight_kg',
            'loss_allowance_kg', '允许亏吨kg', '允许亏吨' => 'loss_allowance_kg',
            'loss_deduct_unit_price', '亏吨扣减单价', '亏吨单价' => 'loss_deduct_unit_price',
            'expected_pickup_at', '预计提货时间' => 'expected_pickup_at',
            'expected_delivery_at', '预计送达时间' => 'expected_delivery_at',
            default => '',
        };
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function resolveCargoCategoryId(array $row): ?int
    {
        $idRaw = $row['cargo_category_id'] ?? null;
        if ($idRaw !== null && $idRaw !== '' && ctype_digit((string) $idRaw)) {
            $id = (int) $idRaw;
            if (CargoCategory::query()->where('id', $id)->exists()) {
                return $id;
            }
        }

        $code = trim((string) ($row['cargo_category_code'] ?? ''));
        if ($code !== '') {
            $id = CargoCategory::query()->where('code', $code)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $name = trim((string) ($row['cargo_category_name'] ?? ''));
        if ($name !== '') {
            $id = CargoCategory::query()->where('name', $name)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function nullableNumber(mixed $value): ?float
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }
        return (float) $text;
    }

    private function nullableInt(mixed $value): ?int
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '' || ! ctype_digit($text)) {
            return null;
        }
        return (int) $text;
    }

    private function canSplitOrMerge(PrePlanOrder $order): bool
    {
        return $this->canPerformOrderAction($order, 'split_merge');
    }

    private function canPerformOrderAction(PrePlanOrder $order, string $action): bool
    {
        if ($action === 'edit') {
            if (! in_array($order->status, ['pending', 'scheduled', 'in_progress'], true)) {
                return false;
            }
            if ((bool) $order->is_locked || $order->status === 'cancelled') {
                return false;
            }
            return $this->canModifyOrder($order);
        }
        if ($action === 'lock') {
            if (! in_array($order->status, ['pending', 'scheduled', 'in_progress'], true)) {
                return false;
            }
            if ((bool) $order->is_locked || $order->status === 'cancelled') {
                return false;
            }
            return $this->canModifyOrder($order);
        }
        if ($action === 'unlock') {
            if (! (bool) $order->is_locked || $order->status === 'cancelled') {
                return false;
            }
            return $this->canModifyOrder($order, true);
        }
        if ($action === 'void') {
            if (! in_array($order->status, ['pending', 'scheduled', 'in_progress'], true)) {
                return false;
            }
            if ($order->status === 'cancelled') {
                return false;
            }
            return $this->canModifyOrder($order);
        }
        if ($action === 'split_merge') {
            if ($order->status !== 'pending') {
                return false;
            }
            if ((bool) $order->is_locked || $order->status === 'cancelled') {
                return false;
            }
            return $this->canModifyOrder($order);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyFreightTemplateToPayload(array $payload, ?\App\Models\User $user = null): array
    {
        $template = $this->resolveFreightTemplate($payload, $user);
        if (! $template) {
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            unset($meta['freight_template_id'], $meta['freight_template_name']);
            $payload['meta'] = $meta;
            return $payload;
        }

        if (empty($payload['freight_calc_scheme'])) {
            $payload['freight_calc_scheme'] = $template->freight_calc_scheme;
        }
        if (! array_key_exists('freight_unit_price', $payload) || $payload['freight_unit_price'] === null || $payload['freight_unit_price'] === '') {
            $payload['freight_unit_price'] = $template->freight_unit_price;
        }
        if (($payload['freight_calc_scheme'] ?? null) === 'by_trip') {
            if (! array_key_exists('freight_trip_count', $payload) || $payload['freight_trip_count'] === null || $payload['freight_trip_count'] === '') {
                $payload['freight_trip_count'] = $template->freight_trip_count ?: 1;
            }
        } else {
            $payload['freight_trip_count'] = 1;
        }
        if (! array_key_exists('loss_allowance_kg', $payload) || $payload['loss_allowance_kg'] === null || $payload['loss_allowance_kg'] === '') {
            $payload['loss_allowance_kg'] = $template->loss_allowance_kg ?? 0;
        }
        if (! array_key_exists('loss_deduct_unit_price', $payload) || $payload['loss_deduct_unit_price'] === null || $payload['loss_deduct_unit_price'] === '') {
            $payload['loss_deduct_unit_price'] = $template->loss_deduct_unit_price;
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['freight_template_id'] = (int) $template->id;
        $meta['freight_template_name'] = (string) $template->name;
        $payload['meta'] = $meta;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function refreshFreightTemplatePayload(array $payload, PrePlanOrder $order, ?\App\Models\User $user = null): array
    {
        $resolvedPayload = $this->applyFreightTemplateToPayload([
            'client_name' => $payload['client_name'] ?? $order->client_name,
            'cargo_category_id' => $payload['cargo_category_id'] ?? $order->cargo_category_id,
            'pickup_site_id' => array_key_exists('pickup_site_id', $payload) ? $payload['pickup_site_id'] : $order->pickup_site_id,
            'pickup_address' => $payload['pickup_address'] ?? $order->pickup_address,
            'dropoff_site_id' => array_key_exists('dropoff_site_id', $payload) ? $payload['dropoff_site_id'] : $order->dropoff_site_id,
            'dropoff_address' => $payload['dropoff_address'] ?? $order->dropoff_address,
            'freight_calc_scheme' => array_key_exists('freight_calc_scheme', $payload) ? $payload['freight_calc_scheme'] : $order->freight_calc_scheme,
            'freight_unit_price' => array_key_exists('freight_unit_price', $payload) ? $payload['freight_unit_price'] : $order->freight_unit_price,
            'freight_trip_count' => array_key_exists('freight_trip_count', $payload) ? $payload['freight_trip_count'] : $order->freight_trip_count,
            'loss_allowance_kg' => array_key_exists('loss_allowance_kg', $payload) ? $payload['loss_allowance_kg'] : $order->loss_allowance_kg,
            'loss_deduct_unit_price' => array_key_exists('loss_deduct_unit_price', $payload) ? $payload['loss_deduct_unit_price'] : $order->loss_deduct_unit_price,
            'meta' => is_array($order->meta) ? $order->meta : [],
        ], $user);

        foreach (['freight_calc_scheme', 'freight_unit_price', 'freight_trip_count', 'loss_allowance_kg', 'loss_deduct_unit_price'] as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                $payload[$field] = $resolvedPayload[$field] ?? null;
            }
        }

        $payload['meta'] = is_array($resolvedPayload['meta'] ?? null) ? $resolvedPayload['meta'] : [];

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveFreightTemplate(array $payload, ?\App\Models\User $user = null): ?FreightRateTemplate
    {
        return $this->freightTemplateMatcherService->match($payload, $user);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function appendOrderHistory(PrePlanOrder $order, string $action, Request $request, array $extra = []): void
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $history = is_array($meta['history'] ?? null) ? $meta['history'] : [];

        $history[] = [
            'at' => now()->toDateTimeString(),
            'action' => $action,
            'operator_id' => $request->user() ? (int) $request->user()->id : null,
            'operator_account' => $request->user()?->account,
            'operator_name' => $request->user()?->name,
            'extra' => $extra,
        ];

        $meta['history'] = array_values($history);
        $order->meta = $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRevisionSnapshot(PrePlanOrder $order): array
    {
        return [
            'cargo_category_id' => $order->cargo_category_id,
            'client_name' => $order->client_name,
            'pickup_address' => $order->pickup_address,
            'pickup_contact_name' => $order->pickup_contact_name,
            'pickup_contact_phone' => $order->pickup_contact_phone,
            'dropoff_address' => $order->dropoff_address,
            'dropoff_contact_name' => $order->dropoff_contact_name,
            'dropoff_contact_phone' => $order->dropoff_contact_phone,
            'cargo_weight_kg' => $order->cargo_weight_kg,
            'cargo_volume_m3' => $order->cargo_volume_m3,
            'freight_calc_scheme' => $order->freight_calc_scheme,
            'freight_unit_price' => $order->freight_unit_price,
            'loss_allowance_kg' => $order->loss_allowance_kg,
            'loss_deduct_unit_price' => $order->loss_deduct_unit_price,
            'expected_pickup_at' => $order->expected_pickup_at?->toDateTimeString(),
            'expected_delivery_at' => $order->expected_delivery_at?->toDateTimeString(),
        ];
    }
}
