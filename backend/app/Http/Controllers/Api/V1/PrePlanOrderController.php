<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrePlanOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PrePlanOrderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            PrePlanOrder::query()->latest()->paginate(20)
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
            'expected_pickup_at' => ['nullable', 'date'],
            'expected_delivery_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $payload['order_no'] = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));

        $order = PrePlanOrder::query()->create($payload);

        return response()->json($order, 201);
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
