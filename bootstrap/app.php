<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        // Casi todas las páginas muestran contenido según la sesión (menú con nombre y correo): el
        // navegador no debe guardarlas, o el botón "atrás" las enseñaría tras cerrar la sesión.
        $middleware->web(append: ['cache.headers:no_store;no_cache;must_revalidate;max_age=0']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($wantsJson);

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
