<?php

use App\Http\Controllers\Admin\RegistrationReviewController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\RegistrationStatusController;
use App\Http\Controllers\Auth\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [WebAuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/register', [WebAuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [WebAuthController::class, 'register'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
    Route::get('/registration/status', [RegistrationStatusController::class, 'show'])->name('registration.status');
    Route::put('/registration/correction', [RegistrationStatusController::class, 'resubmit'])->name('registration.resubmit');

    Route::middleware('approved')->group(function (): void {
        Route::view('/dashboard', 'dashboard')->name('dashboard');

        Route::get('/admin/registrations', [RegistrationReviewController::class, 'index'])->name('registrations.index');
        Route::get('/admin/registrations/{user}', [RegistrationReviewController::class, 'show'])->name('registrations.show');
        Route::post('/admin/registrations/{user}/review', [RegistrationReviewController::class, 'review'])->name('registrations.review');

        Route::get('/admin/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/admin/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::put('/admin/users/{user}/status', [UserController::class, 'status'])->name('users.status');
        Route::put('/admin/users/{user}/roles', [UserController::class, 'roles'])->name('users.roles');
        Route::put('/admin/users/{user}/permissions', [UserController::class, 'permissions'])->name('users.permissions');

        Route::get('/admin/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/admin/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('/admin/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
        Route::put('/admin/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });
});
