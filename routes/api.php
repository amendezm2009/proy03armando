<?php

use App\Http\Controllers\Api\ClassificationController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

Route::post('login', [TokenController::class, 'store'])->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [TokenController::class, 'destroy'])->name('api.logout');
    Route::get('user', [TokenController::class, 'show'])->name('api.user');

    Route::apiResource('classifications', ClassificationController::class)
        ->names('api.classifications');
});
