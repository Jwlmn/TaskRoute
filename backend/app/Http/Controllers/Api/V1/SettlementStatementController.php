<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrePlanOrder;
use App\Models\SettlementStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettlementStatementController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'client_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,confirmed,invoiced,paid'],
        ]);

        $clientName = trim((string) ($payload['client_name'] ?? ''));

        $data = SettlementStatement::query()
            ->when($clientName !== '', fn ($query) => $query->where('client_name', 'like', "%{$clientName}%"))
            ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return response()->json($data);
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:settlement_statements,id'],
        ]);

        $statement = SettlementStatement::query()->findOrFail((int) $payload['id']);
        $orderIds = collect(data_get($statement->meta, 'order_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();
        $orders = PrePlanOrder::query()
            ->whereIn('id', $orderIds->all())
            ->get([
                'id',
                'order_no',
                'client_name',
                'pickup_address',
                'dropoff_address',
                'status',
                'freight_base_amount',
                'freight_loss_deduct_amount',
                'freight_amount',
                'freight_calculated_at',
            ])
            ->sortBy(function (PrePlanOrder $item) use ($orderIds) {
                return $orderIds->search((int) $item->id);
            })
            ->values();

        return response()->json(array_merge($statement->toArray(), [
            'orders' => $orders,
        ]));
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($payload, $request): SettlementStatement {
            $orders = $this->querySettlementOrders($payload['client_name'], $payload['period_start'], $payload['period_end']);
            $summary = $this->buildSummary($orders);

            return SettlementStatement::query()->create([
                'statement_no' => 'ST-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'client_name' => $payload['client_name'],
                'period_start' => $payload['period_start'],
                'period_end' => $payload['period_end'],
                'order_count' => $summary['order_count'],
                'total_base_amount' => $summary['total_base_amount'],
                'total_loss_deduct_amount' => $summary['total_loss_deduct_amount'],
                'total_freight_amount' => $summary['total_freight_amount'],
                'status' => 'draft',
                'created_by' => (int) $request->user()->id,
                'remark' => $payload['remark'] ?? null,
                'meta' => [
                    'order_ids' => $orders->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                ],
            ]);
        });

        return response()->json($result, 201);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:settlement_statements,id'],
            'status' => ['sometimes', 'in:draft,confirmed,invoiced,paid'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $statement = SettlementStatement::query()->findOrFail((int) $payload['id']);
        if (array_key_exists('status', $payload)) {
            $statement->status = $payload['status'];
            if ($payload['status'] === 'confirmed') {
                $statement->confirmed_at = now();
                $statement->confirmed_by = (int) $request->user()->id;
            }
        }
        if (array_key_exists('remark', $payload)) {
            $statement->remark = $payload['remark'];
        }
        $statement->save();

        return response()->json($statement->fresh());
    }

    private function querySettlementOrders(string $clientName, string $periodStart, string $periodEnd): Collection
    {
        return PrePlanOrder::query()
            ->where('status', 'completed')
            ->where('audit_status', 'approved')
            ->where('client_name', $clientName)
            ->whereBetween(DB::raw('date(coalesce(freight_calculated_at, updated_at))'), [$periodStart, $periodEnd])
            ->get([
                'id',
                'freight_base_amount',
                'freight_loss_deduct_amount',
                'freight_amount',
            ]);
    }

    private function buildSummary(Collection $orders): array
    {
        return [
            'order_count' => $orders->count(),
            'total_base_amount' => round((float) $orders->sum(fn ($item) => (float) ($item->freight_base_amount ?? 0)), 2),
            'total_loss_deduct_amount' => round((float) $orders->sum(fn ($item) => (float) ($item->freight_loss_deduct_amount ?? 0)), 2),
            'total_freight_amount' => round((float) $orders->sum(fn ($item) => (float) ($item->freight_amount ?? 0)), 2),
        ];
    }
}
