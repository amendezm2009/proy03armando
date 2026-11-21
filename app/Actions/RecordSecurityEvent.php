<?php

namespace App\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Anota un evento de seguridad en el canal "security" (storage/logs/security-AAAA-MM-DD.log, config/logging.php),
 * aparte del registro general: ahí se revisa quién intentó entrar, quién fue bloqueado y qué accesos se negaron.
 *
 * Cada línea lleva IP, usuario (si hay sesión o token), método, ruta SIN parámetros de la dirección y navegador.
 * Nunca se anotan contraseñas, tokens ni valores de formularios; el correo de un intento fallido va como huella
 * (hash) para poder agrupar intentos sobre una cuenta sin guardar el dato personal.
 *
 * USO: app(RecordSecurityEvent::class)->handle('auth.login_failed', ['email_hash' => RecordSecurityEvent::fingerprint($email)]);
 *
 * Eventos: auth.login, auth.login_failed, auth.lockout, auth.logout, auth.password_reset, auth.password_changed,
 * auth.password_confirm_lockout, auth.token_issued, auth.token_revoked, security.report_viewed (consulta del informe
 * de /admin/seguridad), update.part_uploaded, update.started, update.finished, update.rolled_back, update.reverted y
 * update.discarded (actualizaciones desde /admin/actualizaciones) y http.denied (401, 403, 419 y 429). La revisión programada anota "security.check" aparte.
 */
class RecordSecurityEvent
{
    /** Eventos que indican un problema o un intento de abuso: se anotan como "warning"; el resto, como "info". */
    private const WARNINGS = ['auth.login_failed', 'auth.lockout', 'auth.password_confirm_lockout', 'http.denied', 'update.rolled_back', 'update.reverted'];

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(string $event, array $context = [], ?Request $request = null): void
    {
        $request ??= request();

        $level = in_array($event, self::WARNINGS, true) ? 'warning' : 'info';

        Log::channel('security')->{$level}($event, [
            'ip' => $request->ip(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'user_agent' => Str::limit((string) $request->userAgent(), 120, ''),
            ...$context,
        ]);
    }

    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', Str::lower(trim($value))), 0, 12);
    }
}
