<?php

use App\Actions\RecordSecurityEvent;
use App\Http\Controllers\AvatarFileController;
use App\Http\Controllers\ProductMediaFileController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RestrictPublicOnConfigurationErrors;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Fotos de perfil: ruta pública y SIN grupo de middleware (ni sesión, ni cookies, ni "no-store",
            // ni límite de la API): es una imagen que el navegador debe poder guardar. Ver AvatarFileController.
            Route::get('avatars/{filename}', AvatarFileController::class)
                ->where('filename', '[A-Za-z0-9]{40}\.webp')
                ->name('avatars.show');

            // Fotos, miniaturas y videos de productos: igual que las fotos de perfil. Ver ProductMediaFileController.
            Route::get('media/products/{filename}', ProductMediaFileController::class)
                ->where('filename', '[A-Za-z0-9]{40}\.(webp|mp4|webm)')
                ->name('products.media.file');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        // Mientras una actualización pone la aplicación en mantenimiento, solo siguen disponibles las páginas del
        // actualizador (para seguirla y revertirla) y el acceso, por si la sesión del administrador caduca.
        $middleware->preventRequestsDuringMaintenance(except: ['admin/actualizaciones', 'admin/actualizaciones/*', 'login']);
        // Si cambia la contraseña de una cuenta, sus demás sesiones web dejan de valer (OWASP A07).
        $middleware->authenticateSessions();
        // Cabeceras de seguridad (CSP, X-Frame-Options, HSTS...) en todas las respuestas. Ver SecurityHeaders.
        $middleware->append(SecurityHeaders::class);
        // Casi todas las páginas muestran contenido según la sesión (menú con nombre y correo): el
        // navegador no debe guardarlas, o el botón "atrás" las enseñaría tras cerrar la sesión.
        $middleware->web(append: [
            'cache.headers:no_store;no_cache;must_revalidate;max_age=0',
            EnsureUserIsActive::class,
            // Público y clientes ven "mantenimiento" mientras la configuración de producción tenga errores graves.
            RestrictPublicOnConfigurationErrors::class,
        ]);
        $middleware->api(append: [RestrictPublicOnConfigurationErrors::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($wantsJson);

        // Registro de seguridad (OWASP A09): sin sesión o token (401), acceso denegado (403), formulario con token
        // CSRF vencido (419) y exceso de peticiones (429). Estas excepciones no llegan al registro general. El
        // callback solo anota y devuelve null: la respuesta la arma el resto del manejador como siempre.
        $exceptions->render(function (Throwable $e, Request $request): null {
            $status = match (true) {
                $e instanceof AuthenticationException => 401,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => null,
            };

            if (in_array($status, [401, 403, 419, 429], true)) {
                app(RecordSecurityEvent::class)->handle('http.denied', ['status' => $status], $request);
            }

            return null;
        });

        $exceptions->render(function (QueryException $e, Request $request) use ($wantsJson) {
            $isDuplicate = ($e->errorInfo[1] ?? null) === 1062;

            if (! $isDuplicate && config('app.debug')) {
                return null; // en desarrollo se conserva la pantalla de depuración
            }

            $message = $isDuplicate
                ? 'Ya existe un registro con esos datos.'
                : 'No se pudo completar la operación por un problema con la base de datos. Intenta nuevamente.';

            if ($wantsJson($request)) {
                return response()->json(['message' => $message], $isDuplicate ? 409 : 503);
            }

            if ($isDuplicate && ! $request->isMethodSafe()) {
                return back()->withInput()->with('error', $message);
            }

            return response()->view('errors.503', [], 503);
        });
    })->create();
