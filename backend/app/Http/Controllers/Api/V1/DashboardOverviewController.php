<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\DriverLocation;
use App\Models\PrePlanOrder;
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

        $todayCreatedTasks = DispatchTask::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $todayCompletedTasks = DispatchTask::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$todayStart, $todayEnd])
            ->count();

        $taskCompletionRate = $todayCreatedTasks > 0
            ? round(($todayCompletedTasks / $todayCreatedTasks) * 100, 2)
            : 0;

        return response()->json([
            'metrics' => [
                'pending_pre_plan_orders' => $pendingPrePlanOrders,
                'assigned_tasks' => $assignedTasks,
                'in_progress_tasks' => $inProgressTasks,
                'online_drivers' => $onlineDrivers,
                'exception_alerts' => $exceptionAlerts,
            ],
            'today' => [
                'created_tasks' => $todayCreatedTasks,
                'completed_tasks' => $todayCompletedTasks,
                'task_completion_rate' => $taskCompletionRate,
            ],
            'generated_at' => $now->toDateTimeString(),
        ]);
    }
}
