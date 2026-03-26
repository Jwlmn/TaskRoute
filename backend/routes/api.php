<?php

use App\Http\Controllers\Api\V1\DispatchTaskController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\PrePlanOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/meta', MetaController::class);
    Route::apiResource('pre-plan-orders', PrePlanOrderController::class)->only(['index', 'store', 'show', 'update']);
    Route::apiResource('dispatch-tasks', DispatchTaskController::class)->only(['index', 'store', 'show', 'update']);
});

