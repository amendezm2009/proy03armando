<?php

namespace App\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Confirma la contraseña de quien ya tiene sesión, con un máximo de intentos.
 *
 * USO (en un controlador, antes de la acción sensible):
 *
 *   public function update(PasswordUpdateRequest $request, ConfirmPassword $confirm): RedirectResponse
 *   {
 *       $confirm->handle($request, $request->validated('current_password'));
 *       // ... aquí la contraseña ya es correcta
 *   }
 *
 * RESULTADOS
 *
 *   - Correcta: reinicia el contador y sigue.
 *   - Incorrecta con intentos disponibles: lanza ValidationException sobre "current_password"
 *     indicando cuántos intentos quedan.
 *   - Incorrecta en el último intento, o contador ya agotado (aunque la contraseña sea
 *     correcta): en la web cierra la sesión y redirige al login con un aviso; en la API revoca
 *     el token usado y responde 403 en JSON.
 *   - Cada fallo deja en la sesión "confirm_action" (ruta y método) para que la modal de
 *     confirmación (<x-password-confirm-modal>) se vuelva a abrir sobre la misma acción.
 *
 * El contador es por usuario y se reinicia también al iniciar sesión (AppServiceProvider).
 * El máximo y la caducidad se configuran en config/auth.php ("confirmation").
 */
class ConfirmPassword
{
    /**
     * Clave del contador de intentos de un usuario.
     */
    public static function key(Authenticatable $user): string
    {
        return 'password-confirmation:'.$user->getAuthIdentifier();
    }

    /**
     * @throws ValidationException
     * @throws HttpResponseException
     */
    public function handle(Request $request, string $password): void
    {
        $user = $request->user();
        $key = self::key($user);
        $maxAttempts = (int) config('auth.confirmation.max_attempts');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $this->lockOut($request, $user);
        }

        if (Hash::check($password, $user->getAuthPassword())) {
            RateLimiter::clear($key);

            return;
        }

        RateLimiter::hit($key, (int) config('auth.confirmation.decay_seconds'));

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $this->lockOut($request, $user);
        }

        $remaining = RateLimiter::remaining($key, $maxAttempts);

        // Qué acción se intentó, para que la modal de confirmación vuelva a abrirse sobre ella (solo la ruta).
        if ($request->hasSession()) {
            $request->session()->flash('confirm_action', ['url' => $request->getPathInfo(), 'method' => $request->method()]);
        }

        throw ValidationException::withMessages([
            'current_password' => trans_choice('auth.password_attempts', $remaining, ['remaining' => $remaining]),
        ]);
    }

    /**
     * Web: cierra la sesión y redirige al login. API: revoca el token usado y responde 403 en JSON.
     *
     * @throws HttpResponseException
     */
    private function lockOut(Request $request, Authenticatable $user): never
    {
        Log::warning('Acceso cerrado por demasiados intentos de confirmar la contraseña.', [
            'user_id' => $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        if ($request->is('api/*') || $request->expectsJson()) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            throw new HttpResponseException(response()->json(['message' => __('auth.password_lockout_api')], 403));
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new HttpResponseException(to_route('login')->with('error', __('auth.password_lockout')));
    }
}
