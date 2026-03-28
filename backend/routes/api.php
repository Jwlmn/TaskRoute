<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardOverviewController;
use App\Http\Controllers\Api\V1\DispatchTaskController;
use App\Http\Controllers\Api\V1\DriverTaskExecutionController;
use App\Http\Controllers\Api\V1\DriverLocationController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\PrePlanOrderController;
use App\Http\Controllers\Api\V1\SystemMessageController;
use App\Http\Controllers\Api\V1\Resource\ResourcePersonnelController;
use App\Http\Controllers\Api\V1\Resource\ResourceSiteController;
use App\Http\Controllers\Api\V1\Resource\ResourceVehicleController;
use App\Http\Controllers\Api\V1\SmartDispatchController;
use App\Http\Controllers\Api\V1\FreightRateTemplateController;
use App\Http\Controllers\Api\V1\SettlementStatementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/meta', MetaController::class);

    Route::get('/auth/captcha', [AuthController::class, 'captcha']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::middleware('permission:dashboard')->group(function (): void {
            Route::post('/dashboard/overview', DashboardOverviewController::class);
        });
        Route::middleware('permission:notifications')->group(function (): void {
            Route::post('/message/list', [SystemMessageController::class, 'list']);
            Route::post('/message/read', [SystemMessageController::class, 'markRead']);
            Route::post('/message/read-batch', [SystemMessageController::class, 'markReadBatch']);
            Route::post('/message/pin', [SystemMessageController::class, 'togglePin']);
        });

        Route::middleware(['role:admin|dispatcher', 'permission:dispatch'])->group(function (): void {
            Route::post('/pre-plan-order/list', [PrePlanOrderController::class, 'index']);
            Route::post('/pre-plan-order/create', [PrePlanOrderController::class, 'store']);
            Route::post('/pre-plan-order/batch-create', [PrePlanOrderController::class, 'batchStore']);
            Route::post('/pre-plan-order/import', [PrePlanOrderController::class, 'import']);
            Route::post('/pre-plan-order/detail', [PrePlanOrderController::class, 'showByPayload']);
            Route::post('/pre-plan-order/update', [PrePlanOrderController::class, 'updateByPayload']);
            Route::post('/pre-plan-order/lock', [PrePlanOrderController::class, 'lock']);
            Route::post('/pre-plan-order/unlock', [PrePlanOrderController::class, 'unlock']);
            Route::post('/pre-plan-order/void', [PrePlanOrderController::class, 'void']);
            Route::post('/pre-plan-order/split', [PrePlanOrderController::class, 'split']);
            Route::post('/pre-plan-order/merge', [PrePlanOrderController::class, 'merge']);
            Route::post('/pre-plan-order/audit-list', [PrePlanOrderController::class, 'auditList']);
            Route::post('/pre-plan-order/audit-approve', [PrePlanOrderController::class, 'auditApprove']);
            Route::post('/pre-plan-order/audit-reject', [PrePlanOrderController::class, 'auditReject']);
            Route::post('/pre-plan-order/audit-batch-approve', [PrePlanOrderController::class, 'auditBatchApprove']);
            Route::post('/pre-plan-order/audit-batch-reject', [PrePlanOrderController::class, 'auditBatchReject']);
            Route::post('/pre-plan-order/audit-remark-templates', [PrePlanOrderController::class, 'auditRemarkTemplates']);
            Route::post('/pre-plan-order/audit-timeout-reminder', [PrePlanOrderController::class, 'auditTimeoutReminder']);
            Route::post('/pre-plan-order/revision-compare', [PrePlanOrderController::class, 'revisionCompare']);
        });

        Route::middleware(['role:admin|dispatcher', 'permission:audit_log'])->group(function (): void {
            Route::post('/pre-plan-order/audit-log-list', [PrePlanOrderController::class, 'auditLogList']);
        });

        Route::middleware(['role:admin|dispatcher', 'permission:freight_templates'])->group(function (): void {
            Route::post('/freight-template/list', [FreightRateTemplateController::class, 'list']);
            Route::post('/freight-template/create', [FreightRateTemplateController::class, 'create']);
            Route::post('/freight-template/detail', [FreightRateTemplateController::class, 'detail']);
            Route::post('/freight-template/update', [FreightRateTemplateController::class, 'update']);
        });

        Route::middleware(['role:admin|dispatcher', 'permission:settlement'])->group(function (): void {
            Route::post('/settlement/list', [SettlementStatementController::class, 'list']);
            Route::post('/settlement/create', [SettlementStatementController::class, 'create']);
            Route::post('/settlement/detail', [SettlementStatementController::class, 'detail']);
            Route::post('/settlement/update', [SettlementStatementController::class, 'update']);
        });

        Route::middleware(['role:customer', 'permission:customer_orders'])->group(function (): void {
            Route::post('/pre-plan-order/customer-submit', [PrePlanOrderController::class, 'customerSubmit']);
            Route::post('/pre-plan-order/customer-list', [PrePlanOrderController::class, 'customerList']);
            Route::post('/pre-plan-order/customer-detail', [PrePlanOrderController::class, 'customerDetail']);
            Route::post('/pre-plan-order/customer-update', [PrePlanOrderController::class, 'customerUpdate']);
            Route::post('/pre-plan-order/customer-resubmit', [PrePlanOrderController::class, 'customerResubmit']);
            Route::post('/pre-plan-order/revision-compare', [PrePlanOrderController::class, 'revisionCompare']);
        });

        Route::middleware('permission:dispatch|mobile_tasks')->group(function (): void {
            Route::post('/dispatch-task/list', [DispatchTaskController::class, 'index']);
            Route::post('/dispatch-task/create', [DispatchTaskController::class, 'store']);
            Route::post('/dispatch-task/detail', [DispatchTaskController::class, 'showByPayload']);
            Route::post('/dispatch-task/order-list', [DispatchTaskController::class, 'orderList']);
            Route::post('/dispatch-task/update', [DispatchTaskController::class, 'updateByPayload']);
        });

        Route::middleware(['role:admin|dispatcher', 'permission:dispatch'])->group(function (): void {
            Route::post('/dispatch/preview', [SmartDispatchController::class, 'preview']);
            Route::post('/dispatch/create-tasks', [SmartDispatchController::class, 'createTasks']);
            Route::post('/dispatch/manual-create-tasks', [SmartDispatchController::class, 'manualCreateTasks']);
            Route::post('/dispatch-task/exception-list', [DispatchTaskController::class, 'exceptionList']);
            Route::post('/dispatch-task/exception-handle', [DispatchTaskController::class, 'handleException']);
        });

        Route::middleware(['role:admin|dispatcher', 'permission:mobile_tasks'])->group(function (): void {
            Route::post('/driver-location/latest', [DriverLocationController::class, 'latest']);
            Route::post('/driver-location/trajectory', [DriverLocationController::class, 'trajectory']);
        });

        Route::middleware(['role:admin|dispatcher', 'permission:resources'])->group(function (): void {
            Route::post('/resource/vehicle/list', [ResourceVehicleController::class, 'list']);
            Route::post('/resource/vehicle/create', [ResourceVehicleController::class, 'create']);
            Route::post('/resource/vehicle/detail', [ResourceVehicleController::class, 'detail']);
            Route::post('/resource/vehicle/update', [ResourceVehicleController::class, 'update']);

            Route::post('/resource/site/list', [ResourceSiteController::class, 'list']);
            Route::post('/resource/site/create', [ResourceSiteController::class, 'create']);
            Route::post('/resource/site/detail', [ResourceSiteController::class, 'detail']);
            Route::post('/resource/site/update', [ResourceSiteController::class, 'update']);

            Route::post('/resource/personnel/list', [ResourcePersonnelController::class, 'list']);
            Route::post('/resource/personnel/detail', [ResourcePersonnelController::class, 'detail']);
        });

        Route::middleware(['role:admin', 'permission:users'])->group(function (): void {
            Route::post('/resource/personnel/create', [ResourcePersonnelController::class, 'create']);
            Route::post('/resource/personnel/update', [ResourcePersonnelController::class, 'update']);
        });

        Route::middleware(['role:driver', 'permission:mobile_tasks'])->group(function (): void {
            Route::post('/driver-task/detail', [DriverTaskExecutionController::class, 'detail']);
            Route::post('/driver-task/start', [DriverTaskExecutionController::class, 'start']);
            Route::post('/driver-task/report-exception', [DriverTaskExecutionController::class, 'reportException']);
            Route::post('/driver-task/waypoint-arrive', [DriverTaskExecutionController::class, 'arriveWaypoint']);
            Route::post('/driver-task/waypoint-complete', [DriverTaskExecutionController::class, 'completeWaypoint']);
            Route::post('/driver-task/upload-document', [DriverTaskExecutionController::class, 'uploadDocument']);
            Route::post('/driver-location/report', [DriverLocationController::class, 'report']);
        });
    });
});
