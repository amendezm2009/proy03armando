<?php

use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\ClassificationController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\ResourceAuthorization;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::post('login', [TokenController::class, 'store'])->middleware('throttle:login')->name('api.login');

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
    Route::post('logout', [TokenController::class, 'destroy'])->name('api.logout');
    Route::get('user', [TokenController::class, 'show'])->name('api.user');

    // Foto de perfil: cada persona cambia la suya; un administrador solo puede quitar la de otra.
    Route::post('user/avatar', [AvatarController::class, 'update'])
        ->middleware('throttle:avatar')->name('api.user.avatar.update');
    Route::delete('user/avatar', [AvatarController::class, 'destroy'])
        ->middleware('throttle:avatar')->name('api.user.avatar.destroy');
    Route::delete('users/{user}/avatar', [AvatarController::class, 'destroyFor'])
        ->middleware([ResourceAuthorization::needs(PermissionName::UpdateUsers), 'throttle:avatar'])
        ->can('removeAvatar', 'user')
        ->name('api.users.avatar.destroy');

    ResourceAuthorization::apply(
        Route::apiResource('classifications', ClassificationController::class)
            ->names('api.classifications'),
        'classifications',
    );

    // Usuarios: no se eliminan, se desactivan (ver routes/web.php).
    ResourceAuthorization::apply(
        Route::apiResource('users', UserController::class)->except('destroy')->names('api.users'),
        'users',
        ['view', 'create', 'update'],
    );
    Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->middleware(ResourceAuthorization::needs(PermissionName::DeactivateUsers))
        ->can('deactivate', 'user')
        ->name('api.users.deactivate');
    Route::patch('users/{user}/activate', [UserController::class, 'activate'])
        ->middleware(ResourceAuthorization::needs(PermissionName::DeactivateUsers))
        ->can('activate', 'user')
        ->name('api.users.activate');
    Route::post('users/{user}/invitation', [UserController::class, 'invite'])
        ->middleware([ResourceAuthorization::needs(PermissionName::CreateUsers), 'throttle:invitation'])
        ->can('invite', 'user')
        ->name('api.users.invitation');
});
