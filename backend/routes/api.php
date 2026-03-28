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
use App\Http\Controllers\Api\V1\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/meta', MetaController::class);

    Route::get('/auth/captcha', [AuthController::class, 'captcha']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/dashboard/overview', DashboardOverviewController::class);

        Route::middleware('role:admin,dispatcher')->group(function (): void {
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
            Route::post('/freight-template/list', [FreightRateTemplateController::class, 'list']);
            Route::post('/freight-template/create', [FreightRateTemplateController::class, 'create']);
            Route::post('/freight-template/detail', [FreightRateTemplateController::class, 'detail']);
            Route::post('/freight-template/update', [FreightRateTemplateController::class, 'update']);
            Route::post('/settlement/list', [SettlementStatementController::class, 'list']);
            Route::post('/settlement/create', [SettlementStatementController::class, 'create']);
            Route::post('/settlement/detail', [SettlementStatementController::class, 'detail']);
            Route::post('/settlement/update', [SettlementStatementController::class, 'update']);
        });

        Route::middleware('role:customer')->group(function (): void {
            Route::post('/pre-plan-order/customer-submit', [PrePlanOrderController::class, 'customerSubmit']);
            Route::post('/pre-plan-order/customer-list', [PrePlanOrderController::class, 'customerList']);
            Route::post('/pre-plan-order/customer-detail', [PrePlanOrderController::class, 'customerDetail']);
            Route::post('/pre-plan-order/customer-update', [PrePlanOrderController::class, 'customerUpdate']);
            Route::post('/pre-plan-order/customer-resubmit', [PrePlanOrderController::class, 'customerResubmit']);
            Route::post('/message/list', [SystemMessageController::class, 'list']);
            Route::post('/message/read', [SystemMessageController::class, 'markRead']);
        });

        Route::post('/dispatch-task/list', [DispatchTaskController::class, 'index']);
        Route::post('/dispatch-task/create', [DispatchTaskController::class, 'store']);
        Route::post('/dispatch-task/detail', [DispatchTaskController::class, 'showByPayload']);
        Route::post('/dispatch-task/update', [DispatchTaskController::class, 'updateByPayload']);

        Route::middleware('role:admin,dispatcher')->group(function (): void {
            Route::post('/dispatch/preview', [SmartDispatchController::class, 'preview']);
            Route::post('/dispatch/create-tasks', [SmartDispatchController::class, 'createTasks']);
            Route::post('/dispatch/manual-create-tasks', [SmartDispatchController::class, 'manualCreateTasks']);
            Route::post('/dispatch-task/exception-list', [DispatchTaskController::class, 'exceptionList']);
            Route::post('/dispatch-task/exception-handle', [DispatchTaskController::class, 'handleException']);
            Route::post('/driver-location/latest', [DriverLocationController::class, 'latest']);
            Route::post('/driver-location/trajectory', [DriverLocationController::class, 'trajectory']);

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

        Route::middleware('role:admin')->group(function (): void {
            Route::post('/user/list', [UserManagementController::class, 'index']);
            Route::post('/user/create', [UserManagementController::class, 'store']);
            Route::post('/user/detail', [UserManagementController::class, 'showByPayload']);
            Route::post('/user/update', [UserManagementController::class, 'updateByPayload']);

            Route::post('/resource/personnel/create', [ResourcePersonnelController::class, 'create']);
            Route::post('/resource/personnel/update', [ResourcePersonnelController::class, 'update']);
        });

        Route::middleware('role:driver')->group(function (): void {
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
