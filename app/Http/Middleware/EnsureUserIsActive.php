<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el acceso de quien tenga la cuenta desactivada aunque conserve una sesión o un token.
 *
 * Web: cierra la sesión y envía al login. API: responde 403 en JSON (sus tokens ya se revocan
 * al desactivar). Está en el grupo web (bootstrap/app.php) y en el grupo auth:sanctum de la API.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => __('auth.inactive')], 403);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('error', __('auth.inactive'));
    }
}
