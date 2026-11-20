<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\ClassificationLookupController;
use App\Http\Controllers\ClassificationRecentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProductCodeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductMediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Http\ResourceAuthorization;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Catálogo público para visitantes: sin sesión, solo productos activos y sin datos internos.
Route::prefix('invitado/catalogo')->middleware('throttle:catalog')->name('catalog.')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('index');
    Route::get('{product}', [CatalogController::class, 'show'])->name('show');
});

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

    // Paneles por rol: cada uno exige su permiso (User::homeRoute() lleva a cada persona al suyo).
    Route::get('admin/dashboard', [DashboardController::class, 'admin'])
        ->middleware(ResourceAuthorization::needs(PermissionName::ViewAdminDashboard))
        ->name('admin.dashboard');
    Route::get('cliente/dashboard', [DashboardController::class, 'client'])
        ->middleware(ResourceAuthorization::needs(PermissionName::ViewClientDashboard))
        ->name('client.dashboard');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [PasswordController::class, 'update'])->name('profile.password.update');

    // Foto de perfil: cada persona cambia la suya; un administrador solo puede quitar la de otra.
    Route::post('profile/avatar', [AvatarController::class, 'update'])
        ->middleware('throttle:avatar')->name('profile.avatar.update');
    Route::delete('profile/avatar', [AvatarController::class, 'destroy'])
        ->middleware('throttle:avatar')->name('profile.avatar.destroy');
    Route::delete('users/{user}/avatar', [AvatarController::class, 'destroyFor'])
        ->middleware([ResourceAuthorization::needs(PermissionName::UpdateUsers), 'throttle:avatar'])
        ->can('removeAvatar', 'user')
        ->name('users.avatar.destroy');

    // Búsqueda para el selector de clasificaciones (<x-classification-picker>).
    Route::get('classifications/lookup', ClassificationLookupController::class)
        ->middleware([ResourceAuthorization::needs(PermissionName::ViewClassifications), 'throttle:lookup'])
        ->name('classifications.lookup');
    Route::post('classifications/recent/{classification}', [ClassificationRecentController::class, 'store'])
        ->middleware([ResourceAuthorization::needs(PermissionName::ViewClassifications), 'throttle:lookup'])
        ->name('classifications.recent.store');
    Route::delete('classifications/recent/{classification}', [ClassificationRecentController::class, 'destroy'])
        ->middleware([ResourceAuthorization::needs(PermissionName::ViewClassifications), 'throttle:lookup'])
        ->name('classifications.recent.destroy');
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

    // Productos: la baja es lógica (soft delete). La dirección usa el slug, que no cambia al renombrar.
    ResourceAuthorization::apply(
        Route::resource('products', ProductController::class),
        'products',
    );

    // Código de barras y QR del producto (SVG): verlos o descargarlos exige products.view.
    Route::middleware(ResourceAuthorization::needs(PermissionName::ViewProducts))->group(function () {
        Route::get('products/{product}/barcode.svg', [ProductCodeController::class, 'barcode'])->name('products.barcode');
        Route::get('products/{product}/qr.svg', [ProductCodeController::class, 'qr'])->name('products.qr');
    });

    // Fotos y videos del producto (hasta 9): gestionarlos exige products.update; verlos, products.view (en la ficha).
    Route::middleware([ResourceAuthorization::needs(PermissionName::UpdateProducts), 'throttle:media'])
        ->prefix('products/{product}/media')
        ->name('products.media.')
        ->group(function () {
            Route::post('/', [ProductMediaController::class, 'store'])->name('store');
            Route::delete('{media}', [ProductMediaController::class, 'destroy'])->name('destroy');
            Route::patch('{media}/cover', [ProductMediaController::class, 'cover'])->name('cover');
            Route::patch('{media}/move', [ProductMediaController::class, 'move'])->name('move');
        });

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
