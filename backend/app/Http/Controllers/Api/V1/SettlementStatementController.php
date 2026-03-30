<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrePlanOrder;
use App\Models\SettlementStatement;
use App\Services\Auth\DataScopeService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettlementStatementController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'client_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,confirmed,invoiced,paid'],
        ]);

        $clientName = trim((string) ($payload['client_name'] ?? ''));

        $data = $this->scopedStatementQuery($request)
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

        $statement = $this->scopedStatementQuery($request)->findOrFail((int) $payload['id']);
        $orderIds = collect(data_get($statement->meta, 'order_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();
        $orders = $this->scopedOrderQuery($request)
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
            $orders = $this->querySettlementOrders(
                $request->user(),
                $payload['client_name'],
                $payload['period_start'],
                $payload['period_end']
            );
            if ($orders->isEmpty()) {
                throw ValidationException::withMessages([
                    'client_name' => ['当前筛选条件下没有可生成结算单的已完成订单'],
                ]);
            }
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

        $statement = $this->scopedStatementQuery($request)->findOrFail((int) $payload['id']);
        if (array_key_exists('status', $payload)) {
            $nextStatus = (string) $payload['status'];
            $this->assertValidStatusTransition($statement, $nextStatus);

            $statement->status = $nextStatus;
            if ($nextStatus === 'confirmed' && ! $statement->confirmed_at) {
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

    private function querySettlementOrders(User $user, string $clientName, string $periodStart, string $periodEnd): Collection
    {
        return $this->dataScopeService->applyPrePlanOrderScope(PrePlanOrder::query(), $user)
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

    private function scopedOrderQuery(Request $request): Builder
    {
        return $this->dataScopeService->applyPrePlanOrderScope(PrePlanOrder::query(), $request->user());
    }

    private function scopedStatementQuery(Request $request): Builder
    {
        $query = SettlementStatement::query();
        $user = $request->user();
        if (! $user || $user->hasRole('admin')) {
            return $query;
        }

        $accessibleOrderIds = $this->scopedOrderQuery($request)
            ->pluck('pre_plan_orders.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return $query->where(function (Builder $builder) use ($user, $accessibleOrderIds): void {
            $builder->where('created_by', $user->id);

            foreach ($accessibleOrderIds as $orderId) {
                $builder->orWhereJsonContains('meta->order_ids', $orderId);
            }
        });
    }

    private function assertValidStatusTransition(SettlementStatement $statement, string $nextStatus): void
    {
        $allowedTransitions = [
            'draft' => ['confirmed'],
            'confirmed' => ['invoiced'],
            'invoiced' => ['paid'],
            'paid' => [],
        ];

        $currentStatus = (string) $statement->status;
        if ($currentStatus === $nextStatus) {
            return;
        }

        if (! in_array($nextStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ['结算单状态仅支持按 draft -> confirmed -> invoiced -> paid 顺序流转'],
            ]);
        }
    }
}
