<?php

use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PersonnelController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\WorkOrderCategoryController;
use App\Http\Controllers\Api\WorkOrderController;
use App\Http\Controllers\Api\WorkOrderWorkflowController;
use App\Http\Controllers\Auth\ApiAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn (): array => ['status' => 'ok']);
    Route::post('/auth/login', [ApiAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'api.token', 'approved'])->group(function (): void {
        Route::get('/me', [ApiAuthController::class, 'me']);
        Route::post('/auth/logout', [ApiAuthController::class, 'logout']);
        Route::get('/me/profile', [ProfileController::class, 'show']);
        Route::patch('/me/profile', [ProfileController::class, 'update']);
        Route::get('/personnel', [PersonnelController::class, 'index']);
        Route::get('/personnel/{personnel}', [PersonnelController::class, 'show']);
        Route::get('/skills', [PersonnelController::class, 'skills']);
        Route::get('/campuses', [LocationController::class, 'campuses']);
        Route::get('/buildings', [LocationController::class, 'buildings']);
        Route::get('/locations', [LocationController::class, 'locations']);
        Route::get('/work-orders', [WorkOrderController::class, 'index']);
        Route::get('/work-order-categories', [WorkOrderCategoryController::class, 'index']);
        Route::post('/work-orders', [WorkOrderController::class, 'store']);
        Route::post('/work-orders/direct', [WorkOrderController::class, 'storeDirect']);
        Route::post('/work-orders/{workOrder}/workflow/{action}', [WorkOrderWorkflowController::class, 'action']);
        Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show']);
        Route::patch('/work-orders/{workOrder}', [WorkOrderController::class, 'update']);
        Route::get('/work-orders/{workOrder}/attachments/{attachment}', [WorkOrderController::class, 'attachment']);
    });
});
