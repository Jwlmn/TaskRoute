<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrePlanOrder;
use App\Models\SystemMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrePlanOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,scheduled,in_progress,completed,cancelled'],
            'audit_status' => ['nullable', 'in:pending_approval,approved,rejected'],
            'is_locked' => ['nullable', 'boolean'],
            'cargo_category_id' => ['nullable', 'integer', 'exists:cargo_categories,id'],
            'expected_pickup_from' => ['nullable', 'date'],
            'expected_pickup_to' => ['nullable', 'date'],
        ]);

        return response()->json(
            PrePlanOrder::query()
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
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_contact_name' => ['nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['nullable', 'string', 'max:32'],
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

        $order = PrePlanOrder::query()->create($payload);

        return response()->json($order, 201);
    }

    public function batchStore(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'orders' => ['required', 'array', 'min:1', 'max:200'],
            'orders.*.cargo_category_id' => ['required', 'integer', 'exists:cargo_categories,id'],
            'orders.*.client_name' => ['required', 'string', 'max:255'],
            'orders.*.pickup_address' => ['required', 'string', 'max:255'],
            'orders.*.pickup_contact_name' => ['nullable', 'string', 'max:64'],
            'orders.*.pickup_contact_phone' => ['nullable', 'string', 'max:32'],
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

                return PrePlanOrder::query()->create($orderPayload);
            });
        });

        return response()->json([
            'count' => $createdOrders->count(),
            'data' => $createdOrders->values(),
        ], 201);
    }

    public function customerSubmit(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'cargo_category_id' => ['required', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_contact_name' => ['nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['nullable', 'string', 'max:32'],
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

        $order = PrePlanOrder::query()->create($payload);

        return response()->json($order, 201);
    }

    public function customerList(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'audit_status' => ['nullable', 'in:pending_approval,approved,rejected'],
        ]);

        return response()->json(
            PrePlanOrder::query()
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

        $order = PrePlanOrder::query()->findOrFail($payload['id']);
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
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
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

        $order = PrePlanOrder::query()->findOrFail($payload['id']);
        if ((int) $order->submitter_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($order->audit_status !== 'rejected') {
            return response()->json(['message' => '仅已驳回计划单可由客户修改'], 422);
        }
        if (! $this->canModifyOrder($order)) {
            return response()->json(['message' => '关联任务节点已到达/完成，预计划单不可修改'], 422);
        }

        unset($payload['id']);
        $order->update($payload);

        return response()->json($order->fresh());
    }

    public function customerResubmit(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        return DB::transaction(function () use ($payload, $request): JsonResponse {
            $order = PrePlanOrder::query()->lockForUpdate()->findOrFail($payload['id']);
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
            PrePlanOrder::query()
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

        $order = PrePlanOrder::query()->findOrFail($payload['id']);
        if (! $this->canModifyOrder($order)) {
            return response()->json(['message' => '关联任务节点已到达/完成，预计划单不可锁定'], 422);
        }
        if ($order->status === 'cancelled') {
            return response()->json(['message' => '已作废计划单不可锁定'], 422);
        }

        $order->is_locked = true;
        $order->save();

        return response()->json($order->fresh());
    }

    public function unlock(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        $order = PrePlanOrder::query()->findOrFail($payload['id']);
        if (! $this->canModifyOrder($order, true)) {
            return response()->json(['message' => '关联任务节点已到达/完成，预计划单不可解锁'], 422);
        }

        $order->is_locked = false;
        $order->save();

        return response()->json($order->fresh());
    }

    public function void(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'void_remark' => ['required', 'string', 'max:255'],
        ]);

        $order = PrePlanOrder::query()->findOrFail($payload['id']);
        if ($order->status === 'cancelled') {
            return response()->json(['message' => '计划单已作废，请勿重复操作'], 422);
        }
        if (! $this->canModifyOrder($order)) {
            return response()->json(['message' => '关联任务节点已到达/完成，预计划单不可作废'], 422);
        }

        $order->status = 'cancelled';
        $order->voided_by = (int) $request->user()->id;
        $order->voided_at = now();
        $order->void_remark = $payload['void_remark'];
        $order->save();

        return response()->json($order->fresh());
    }

    public function auditApprove(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'audit_remark' => ['nullable', 'string', 'max:255'],
        ]);

        $order = DB::transaction(function () use ($payload, $request): PrePlanOrder {
            $order = PrePlanOrder::query()->lockForUpdate()->findOrFail($payload['id']);
            if ($order->audit_status !== 'pending_approval') {
                abort(422, '仅待审核计划单可执行审核通过');
            }

            $order->audit_status = 'approved';
            $order->audited_by = (int) $request->user()->id;
            $order->audited_at = now();
            $order->audit_remark = $payload['audit_remark'] ?? null;
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

    public function auditReject(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'audit_remark' => ['required', 'string', 'max:255'],
        ]);

        $order = DB::transaction(function () use ($payload, $request): PrePlanOrder {
            $order = PrePlanOrder::query()->lockForUpdate()->findOrFail($payload['id']);
            if ($order->audit_status !== 'pending_approval') {
                abort(422, '仅待审核计划单可执行驳回');
            }

            $order->audit_status = 'rejected';
            $order->audited_by = (int) $request->user()->id;
            $order->audited_at = now();
            $order->audit_remark = $payload['audit_remark'];
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

    public function show(PrePlanOrder $prePlanOrder): JsonResponse
    {
        return response()->json($prePlanOrder);
    }

    public function showByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
        ]);

        $prePlanOrder = PrePlanOrder::query()->findOrFail($payload['id']);

        return response()->json($prePlanOrder);
    }

    public function update(Request $request, PrePlanOrder $prePlanOrder): JsonResponse
    {
        if (! $this->canModifyOrder($prePlanOrder)) {
            return response()->json(['message' => '关联任务节点已到达/完成，预计划单不可修改'], 422);
        }

        $payload = $request->validate([
            'cargo_category_id' => ['sometimes', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['sometimes', 'string', 'max:255'],
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
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

        $prePlanOrder->update($payload);

        return response()->json($prePlanOrder->fresh());
    }

    public function updateByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:pre_plan_orders,id'],
            'cargo_category_id' => ['sometimes', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['sometimes', 'string', 'max:255'],
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_contact_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickup_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
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

        $prePlanOrder = PrePlanOrder::query()->findOrFail($payload['id']);
        if (! $this->canModifyOrder($prePlanOrder)) {
            return response()->json(['message' => '关联任务节点已到达/完成，预计划单不可修改'], 422);
        }
        unset($payload['id']);
        $prePlanOrder->update($payload);

        return response()->json($prePlanOrder->fresh());
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
}
