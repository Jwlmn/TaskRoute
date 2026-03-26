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

        Route::apiResource('pre-plan-orders', PrePlanOrderController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('dispatch-tasks', DispatchTaskController::class)->only(['index', 'store', 'show', 'update']);

        Route::middleware('role:admin,dispatcher')->group(function (): void {
            Route::post('/dispatch/preview', [SmartDispatchController::class, 'preview']);
            Route::post('/dispatch/create-tasks', [SmartDispatchController::class, 'createTasks']);
        });

        Route::middleware('role:admin')->group(function (): void {
            Route::apiResource('users', UserManagementController::class)->only(['index', 'store', 'show', 'update']);
        });
    });
});
