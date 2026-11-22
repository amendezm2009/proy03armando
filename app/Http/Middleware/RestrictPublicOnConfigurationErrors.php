<?php

namespace App\Http\Middleware;

use App\Actions\RunSecurityChecks;
use App\PermissionName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras la configuración de PRODUCCIÓN tenga errores graves (las mismas revisiones que marcan FALLA en
 * `php artisan security:check`: APP_DEBUG activo, sin HTTPS, clave privada de firma en el servidor, etc.), el público
 * y los clientes ven la página de mantenimiento (503). Los administradores (quien puede ver el informe de seguridad o
 * gestionar actualizaciones) entran a todo el sitio: así corrigen el problema, prueban a mano y, cuando ya no hay
 * errores, el sitio se abre solo (la página de mantenimiento se recarga y muestra la página que cada persona pedía).
 *
 * Siempre quedan disponibles el acceso (para que el administrador pueda iniciar sesión), el cierre de sesión y /health.
 * Al público no se le dice qué falla: solo "mantenimiento". Se desactiva con SECURITY_BLOCK_ON_ERRORS=false (ver
 * config/security.php) y fuera de producción nunca actúa.
 *
 * A los administradores se les avisa con un banner (variable de vista "publicBlocked") de que el público no puede entrar.
 */
class RestrictPublicOnConfigurationErrors
{
    /** Direcciones que nunca se bloquean. */
    private const ALWAYS_AVAILABLE = ['login', 'logout', 'health', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.block_on_errors') || ! app()->isProduction() || $request->is(self::ALWAYS_AVAILABLE)) {
            return $next($request);
        }

        if (! app(RunSecurityChecks::class)->handle()->contains('status', 'FALLA')) {
            return $next($request);
        }

        if ($request->user()?->canAny([PermissionName::ViewSecurityReport->value, PermissionName::ManageUpdates->value])) {
            view()->share('publicBlocked', true);

            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'El sistema está en mantenimiento. Intenta nuevamente en unos minutos.'], 503, ['Retry-After' => 30]);
        }

        return response()->view('errors.503', ['maintenance' => true], 503, ['Retry-After' => 30]);
    }
}
