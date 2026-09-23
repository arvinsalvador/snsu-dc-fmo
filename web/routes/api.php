<?php

use App\Http\Controllers\Auth\ApiAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn (): array => ['status' => 'ok']);
    Route::post('/auth/login', [ApiAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'api.token', 'approved'])->group(function (): void {
        Route::get('/me', [ApiAuthController::class, 'me']);
        Route::post('/auth/logout', [ApiAuthController::class, 'logout']);
    });
});
