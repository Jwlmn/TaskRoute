<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DispatchTaskController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\PrePlanOrderController;
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

        Route::post('/pre-plan-order/list', [PrePlanOrderController::class, 'index']);
        Route::post('/pre-plan-order/create', [PrePlanOrderController::class, 'store']);
        Route::post('/pre-plan-order/detail', [PrePlanOrderController::class, 'showByPayload']);
        Route::post('/pre-plan-order/update', [PrePlanOrderController::class, 'updateByPayload']);

        Route::post('/dispatch-task/list', [DispatchTaskController::class, 'index']);
        Route::post('/dispatch-task/create', [DispatchTaskController::class, 'store']);
        Route::post('/dispatch-task/detail', [DispatchTaskController::class, 'showByPayload']);
        Route::post('/dispatch-task/update', [DispatchTaskController::class, 'updateByPayload']);

        Route::middleware('role:admin,dispatcher')->group(function (): void {
            Route::post('/dispatch/preview', [SmartDispatchController::class, 'preview']);
            Route::post('/dispatch/create-tasks', [SmartDispatchController::class, 'createTasks']);
        });

        Route::middleware('role:admin')->group(function (): void {
            Route::post('/user/list', [UserManagementController::class, 'index']);
            Route::post('/user/create', [UserManagementController::class, 'store']);
            Route::post('/user/detail', [UserManagementController::class, 'showByPayload']);
            Route::post('/user/update', [UserManagementController::class, 'updateByPayload']);
        });
    });
});
