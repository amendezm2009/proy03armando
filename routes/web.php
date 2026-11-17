<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Http\ResourceAuthorization;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:register')->name('register.store');

    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:password')->name('password.email');
    Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])->middleware('throttle:password')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [PasswordController::class, 'update'])->name('profile.password.update');

    Route::get('classifications/export/excel', [ClassificationController::class, 'export'])
        ->middleware(ResourceAuthorization::needs(PermissionName::ViewClassifications))
        ->name('classifications.export');
    Route::post('classifications/import', [ClassificationController::class, 'import'])
        ->middleware(ResourceAuthorization::needs(PermissionName::CreateClassifications))
        ->name('classifications.import');
    ResourceAuthorization::apply(
        Route::resource('classifications', ClassificationController::class)->except('show'),
        'classifications',
    );

    // Usuarios: no se eliminan, se desactivan. El permiso del rol lo exige el middleware y la regla
    // por registro (no a sí mismo, no a la última persona administradora) UserPolicy con ->can().
    ResourceAuthorization::apply(
        Route::resource('users', UserController::class)->except(['show', 'destroy']),
        'users',
        ['view', 'create', 'update'],
    );
    Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->middleware(ResourceAuthorization::needs(PermissionName::DeactivateUsers))
        ->can('deactivate', 'user')
        ->name('users.deactivate');
    Route::patch('users/{user}/activate', [UserController::class, 'activate'])
        ->middleware(ResourceAuthorization::needs(PermissionName::DeactivateUsers))
        ->can('activate', 'user')
        ->name('users.activate');
    Route::post('users/{user}/invitation', [UserController::class, 'invite'])
        ->middleware([ResourceAuthorization::needs(PermissionName::CreateUsers), 'throttle:invitation'])
        ->can('invite', 'user')
        ->name('users.invitation');
});
