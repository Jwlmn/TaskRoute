<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrePlanOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrePlanOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'audit_status' => ['nullable', 'in:pending_approval,approved,rejected'],
        ]);

        return response()->json(
            PrePlanOrder::query()
                ->when($payload['audit_status'] ?? null, fn ($query, $auditStatus) => $query->where('audit_status', $auditStatus))
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
            'dropoff_address' => ['required', 'string', 'max:255'],
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

    public function customerSubmit(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'cargo_category_id' => ['required', 'integer', 'exists:cargo_categories,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'dropoff_address' => ['required', 'string', 'max:255'],
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

    public function auditList(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'audit_status' => ['nullable', 'in:pending_approval,approved,rejected'],
            'submitter_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return response()->json(
            PrePlanOrder::query()
                ->when($payload['audit_status'] ?? null, fn ($query, $auditStatus) => $query->where('audit_status', $auditStatus))
                ->when($payload['submitter_id'] ?? null, fn ($query, $submitterId) => $query->where('submitter_id', $submitterId))
                ->latest()
                ->paginate(20)
        );
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
            'dropoff_address' => ['sometimes', 'string', 'max:255'],
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
            'dropoff_address' => ['sometimes', 'string', 'max:255'],
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

    private function canModifyOrder(PrePlanOrder $prePlanOrder): bool
    {
        return ! $prePlanOrder->dispatchTasks()
            ->whereHas('waypoints', fn ($query) => $query->whereIn('status', ['arrived', 'completed']))
            ->exists();
    }
}
