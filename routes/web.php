<?php

use App\Http\Controllers\ClassificationController;
use Illuminate\Support\Facades\Route;

Route::get('classifications/export/excel', [ClassificationController::class, 'export'])->name('classifications.export');
Route::post('classifications/import', [ClassificationController::class, 'import'])->name('classifications.import');
Route::resource('classifications', ClassificationController::class)->except('show');

Route::get('/', function () {
    return view('welcome');
});
