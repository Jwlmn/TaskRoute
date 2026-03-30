<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\DriverLocation;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\Vehicle;
use App\Services\Auth\DataScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DashboardOverviewController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function __invoke(): JsonResponse
    {
        $now = CarbonImmutable::now();
        $todayStart = $now->startOfDay();
        $todayEnd = $now->endOfDay();
        $onlineThreshold = $now->subMinutes(15);

        $pendingPrePlanOrders = $this->dataScopeService->applyPrePlanOrderScope(PrePlanOrder::query(), request()->user())
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('audit_status')
                    ->orWhere('audit_status', 'approved');
            })
            ->count();

        $pendingApprovalOrders = $this->dataScopeService->applyPrePlanOrderScope(PrePlanOrder::query(), request()->user())
            ->where('audit_status', 'pending_approval')
            ->count();

        $inProgressTasks = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), request()->user())
            ->where('status', 'in_progress')
            ->count();

        $assignedTasks = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), request()->user())
            ->where('status', 'assigned')
            ->count();

        $onlineDrivers = DriverLocation::query()
            ->where('located_at', '>=', $onlineThreshold)
            ->distinct('driver_id')
            ->count('driver_id');

        $scopedTaskQuery = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), request()->user());

        $exceptionAlerts = (clone $scopedTaskQuery)
            ->where('route_meta->exception->status', 'pending')
            ->count();

        $totalVehicles = $this->dataScopeService->applyVehicleScope(Vehicle::query(), request()->user())->count();
        $busyVehicles = $this->dataScopeService->applyVehicleScope(Vehicle::query(), request()->user())
            ->where('status', 'busy')
            ->count();

        $todayCreatedTasks = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), request()->user())
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $todayCompletedTasks = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), request()->user())
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$todayStart, $todayEnd])
            ->count();

        $todayCompletedOrdersQuery = $this->dataScopeService->applyPrePlanOrderScope(PrePlanOrder::query(), request()->user())
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$todayStart, $todayEnd]);

        $todayCompletedOrders = (clone $todayCompletedOrdersQuery)->count();
        $todayTotalFreightAmount = round((float) ((clone $todayCompletedOrdersQuery)->sum('freight_amount') ?? 0), 2);

        $todayOnTimeOrders = (clone $todayCompletedOrdersQuery)
            ->whereNotNull('expected_delivery_at')
            ->whereColumn('updated_at', '<=', 'expected_delivery_at')
            ->count();
        $todayOnTimeOrderBase = (clone $todayCompletedOrdersQuery)
            ->whereNotNull('expected_delivery_at')
            ->count();

        $todayReceiptUploadedTasks = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), request()->user())
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$todayStart, $todayEnd])
            ->whereHas('documents', function ($query) use ($todayStart, $todayEnd): void {
                $query->whereIn('document_type', ['receipt', 'signoff'])
                    ->whereBetween('uploaded_at', [$todayStart, $todayEnd]);
            })
            ->count();

        $todayDriverTaskBase = (clone $scopedTaskQuery)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereNotNull('driver_id')
            ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'completed'])
            ->count();

        $todayCompletedDriverTasks = (clone $scopedTaskQuery)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereNotNull('driver_id')
            ->where('status', 'completed')
            ->count();

        $siteStats = $this->dataScopeService->applySiteScope(LogisticsSite::query(), request()->user())
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function (LogisticsSite $site) use ($scopedTaskQuery): array {
                return [
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'region_code' => $site->region_code,
                    'pending_pre_plan_orders' => PrePlanOrder::query()
                        ->where('status', 'pending')
                        ->where(function ($query): void {
                            $query->whereNull('audit_status')
                                ->orWhere('audit_status', 'approved');
                        })
                        ->where(function ($query) use ($site): void {
                            $query->where('pickup_site_id', $site->id)
                                ->orWhere('dropoff_site_id', $site->id);
                        })
                        ->count(),
                    'assigned_tasks' => (clone $scopedTaskQuery)
                        ->where('status', 'assigned')
                        ->whereHas('vehicle', fn ($query) => $query->where('site_id', $site->id))
                        ->count(),
                    'in_progress_tasks' => (clone $scopedTaskQuery)
                        ->whereIn('status', ['accepted', 'in_progress'])
                        ->whereHas('vehicle', fn ($query) => $query->where('site_id', $site->id))
                        ->count(),
                    'busy_vehicles' => Vehicle::query()
                        ->where('site_id', $site->id)
                        ->where('status', 'busy')
                        ->count(),
                ];
            })
            ->values()
            ->all();

        $pendingExceptions = (clone $scopedTaskQuery)
            ->with(['driver:id,account,name', 'vehicle:id,plate_number,name,site_id'])
            ->where('route_meta->exception->status', 'pending')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (DispatchTask $task): array {
                $exception = is_array($task->route_meta) ? ($task->route_meta['exception'] ?? []) : [];

                return [
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                    'status' => $task->status,
                    'driver_name' => $task->driver?->name,
                    'driver_account' => $task->driver?->account,
                    'vehicle_plate_number' => $task->vehicle?->plate_number,
                    'vehicle_name' => $task->vehicle?->name,
                    'exception_type' => $exception['type'] ?? null,
                    'exception_description' => $exception['description'] ?? null,
                    'reported_at' => $exception['reported_at'] ?? null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'metrics' => [
                'pending_pre_plan_orders' => $pendingPrePlanOrders,
                'pending_approval_orders' => $pendingApprovalOrders,
                'assigned_tasks' => $assignedTasks,
                'in_progress_tasks' => $inProgressTasks,
                'online_drivers' => $onlineDrivers,
                'exception_alerts' => $exceptionAlerts,
                'busy_vehicles' => $busyVehicles,
                'total_vehicles' => $totalVehicles,
            ],
            'today' => [
                'created_tasks' => $todayCreatedTasks,
                'completed_tasks' => $todayCompletedTasks,
                'completed_orders' => $todayCompletedOrders,
                'receipt_uploaded_tasks' => $todayReceiptUploadedTasks,
                'total_freight_amount' => $todayTotalFreightAmount,
            ],
            'rates' => [
                'task_completion_rate' => $this->percentage($todayCompletedTasks, $todayCreatedTasks),
                'vehicle_utilization_rate' => $this->percentage($busyVehicles, $totalVehicles),
                'on_time_order_rate' => $this->percentage($todayOnTimeOrders, $todayOnTimeOrderBase),
                'receipt_upload_rate' => $this->percentage($todayReceiptUploadedTasks, $todayCompletedTasks),
                'driver_fulfillment_rate' => $this->percentage($todayCompletedDriverTasks, $todayDriverTaskBase),
            ],
            'site_stats' => $siteStats,
            'pending_exceptions' => $pendingExceptions,
            'generated_at' => $now->toDateTimeString(),
        ]);
    }

    private function percentage(int|float $numerator, int|float $denominator): float
    {
        if ($denominator <= 0) {
            return 0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }
}
