<?php

use App\Http\Controllers\Admin\RegistrationReviewController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\RegistrationStatusController;
use App\Http\Controllers\Auth\WebAuthController;
use App\Http\Controllers\Locations\LocationManagementController;
use App\Http\Controllers\Personnel\PersonnelController;
use App\Http\Controllers\Personnel\SkillController;
use App\Http\Controllers\ProfileController;
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
        Route::get('/locations/campuses', [LocationManagementController::class, 'campuses'])->name('campuses.index');
        Route::post('/locations/campuses', [LocationManagementController::class, 'campusStore'])->name('campuses.store');
        Route::put('/locations/campuses/{campus}', [LocationManagementController::class, 'campusUpdate'])->name('campuses.update');
        Route::delete('/locations/campuses/{campus}', [LocationManagementController::class, 'campusDelete'])->name('campuses.destroy');
        Route::get('/locations/buildings', [LocationManagementController::class, 'buildings'])->name('buildings.index');
        Route::post('/locations/buildings', [LocationManagementController::class, 'buildingStore'])->name('buildings.store');
        Route::get('/locations/buildings/{building}', [LocationManagementController::class, 'buildingShow'])->name('buildings.show');
        Route::put('/locations/buildings/{building}', [LocationManagementController::class, 'buildingUpdate'])->name('buildings.update');
        Route::delete('/locations/buildings/{building}', [LocationManagementController::class, 'buildingDelete'])->name('buildings.destroy');
        Route::post('/locations/buildings/{building}/floors', [LocationManagementController::class, 'floorStore'])->name('floors.store');
        Route::get('/locations', [LocationManagementController::class, 'locations'])->name('locations.index');
        Route::post('/locations', [LocationManagementController::class, 'locationStore'])->name('locations.store');
        Route::put('/locations/{location}', [LocationManagementController::class, 'locationUpdate'])->name('locations.update');
        Route::delete('/locations/{location}', [LocationManagementController::class, 'locationDelete'])->name('locations.destroy');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::get('/personnel', [PersonnelController::class, 'index'])->name('personnel.index');
        Route::get('/personnel/create', [PersonnelController::class, 'create'])->name('personnel.create');
        Route::post('/personnel', [PersonnelController::class, 'store'])->name('personnel.store');
        Route::get('/personnel/{personnel}', [PersonnelController::class, 'show'])->name('personnel.show');
        Route::get('/personnel/{personnel}/edit', [PersonnelController::class, 'edit'])->name('personnel.edit');
        Route::put('/personnel/{personnel}', [PersonnelController::class, 'update'])->name('personnel.update');
        Route::put('/personnel/{personnel}/status', [PersonnelController::class, 'updateStatus'])->name('personnel.status');
        Route::delete('/personnel/{personnel}', [PersonnelController::class, 'destroy'])->name('personnel.destroy');

        Route::get('/skills', [SkillController::class, 'index'])->name('skills.index');
        Route::post('/skills', [SkillController::class, 'store'])->name('skills.store');
        Route::put('/skills/{skill}', [SkillController::class, 'update'])->name('skills.update');
        Route::delete('/skills/{skill}', [SkillController::class, 'destroy'])->name('skills.destroy');

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
