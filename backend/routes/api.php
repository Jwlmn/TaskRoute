<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DispatchTaskController;
use App\Http\Controllers\Api\V1\DriverTaskExecutionController;
use App\Http\Controllers\Api\V1\DriverLocationController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\PrePlanOrderController;
use App\Http\Controllers\Api\V1\Resource\ResourcePersonnelController;
use App\Http\Controllers\Api\V1\Resource\ResourceSiteController;
use App\Http\Controllers\Api\V1\Resource\ResourceVehicleController;
use App\Http\Controllers\Api\V1\SmartDispatchController;
use App\Http\Controllers\Api\V1\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/meta', MetaController::class);

    Route::get('/auth/captcha', [AuthController::class, 'captcha']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('role:admin,dispatcher')->group(function (): void {
            Route::post('/pre-plan-order/list', [PrePlanOrderController::class, 'index']);
            Route::post('/pre-plan-order/create', [PrePlanOrderController::class, 'store']);
            Route::post('/pre-plan-order/detail', [PrePlanOrderController::class, 'showByPayload']);
            Route::post('/pre-plan-order/update', [PrePlanOrderController::class, 'updateByPayload']);
        });

        Route::post('/dispatch-task/list', [DispatchTaskController::class, 'index']);
        Route::post('/dispatch-task/create', [DispatchTaskController::class, 'store']);
        Route::post('/dispatch-task/detail', [DispatchTaskController::class, 'showByPayload']);
        Route::post('/dispatch-task/update', [DispatchTaskController::class, 'updateByPayload']);

        Route::middleware('role:admin,dispatcher')->group(function (): void {
            Route::post('/dispatch/preview', [SmartDispatchController::class, 'preview']);
            Route::post('/dispatch/create-tasks', [SmartDispatchController::class, 'createTasks']);
            Route::post('/dispatch/manual-create-tasks', [SmartDispatchController::class, 'manualCreateTasks']);
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
            Route::post('/driver-task/waypoint-arrive', [DriverTaskExecutionController::class, 'arriveWaypoint']);
            Route::post('/driver-task/waypoint-complete', [DriverTaskExecutionController::class, 'completeWaypoint']);
            Route::post('/driver-task/upload-document', [DriverTaskExecutionController::class, 'uploadDocument']);
            Route::post('/driver-location/report', [DriverLocationController::class, 'report']);
        });
    });
});
