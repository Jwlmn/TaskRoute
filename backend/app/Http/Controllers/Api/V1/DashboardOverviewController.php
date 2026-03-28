<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\DriverLocation;
use App\Models\PrePlanOrder;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DashboardOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $now = CarbonImmutable::now();
        $todayStart = $now->startOfDay();
        $todayEnd = $now->endOfDay();
        $onlineThreshold = $now->subMinutes(15);

        $pendingPrePlanOrders = PrePlanOrder::query()
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('audit_status')
                    ->orWhere('audit_status', 'approved');
            })
            ->count();

        $pendingApprovalOrders = PrePlanOrder::query()
            ->where('audit_status', 'pending_approval')
            ->count();

        $inProgressTasks = DispatchTask::query()
            ->where('status', 'in_progress')
            ->count();

        $assignedTasks = DispatchTask::query()
            ->where('status', 'assigned')
            ->count();

        $onlineDrivers = DriverLocation::query()
            ->where('located_at', '>=', $onlineThreshold)
            ->distinct('driver_id')
            ->count('driver_id');

        $exceptionAlerts = DispatchTask::query()
            ->where('status', 'cancelled')
            ->count();

        $totalVehicles = Vehicle::query()->count();
        $busyVehicles = Vehicle::query()
            ->where('status', 'busy')
            ->count();

        $todayCreatedTasks = DispatchTask::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $todayCompletedTasks = DispatchTask::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$todayStart, $todayEnd])
            ->count();

        $todayCompletedOrdersQuery = PrePlanOrder::query()
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

        $todayReceiptUploadedTasks = DispatchTask::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$todayStart, $todayEnd])
            ->whereHas('documents', function ($query) use ($todayStart, $todayEnd): void {
                $query->whereIn('document_type', ['receipt', 'signoff'])
                    ->whereBetween('uploaded_at', [$todayStart, $todayEnd]);
            })
            ->count();

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
            ],
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
