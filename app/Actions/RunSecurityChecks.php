<?php

namespace App\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Revisa la configuración de seguridad de la instalación (OWASP A02, A05, A07 y A09). La usan el comando
 * `php artisan security:check` y la página de administradores `/admin/seguridad`, así ambos dicen lo mismo.
 *
 * Cada fila: status (OK, AVISO o FALLA), label (qué se revisa) y fix (cómo corregirlo, vacío si está bien).
 * Una revisión grave que no se cumple es FALLA cuando la app corre en producción o cuando $strict es true
 * (evaluar el .env actual como si fuera producción); si no, solo es AVISO.
 *
 * Nunca devuelve valores de la configuración (claves, URLs de la base de datos): solo si están bien puestos.
 */
class RunSecurityChecks
{
    /**
     * @return Collection<int, array{status: string, label: string, fix: string}>
     */
    public function handle(bool $strict = false): Collection
    {
        $production = $strict || app()->isProduction();

        return collect($this->checks())->map(function (array $check) use ($production): array {
            [$label, $passes, $fix, $critical] = $check;

            return [
                'status' => $passes ? 'OK' : ($critical && $production ? 'FALLA' : 'AVISO'),
                'label' => $label,
                'fix' => $passes ? '' : $fix,
            ];
        });
    }

    /**
     * Cada revisión: nombre, si se cumple, cómo corregirla y si es grave (falla en producción).
     *
     * @return list<array{string, bool, string, bool}>
     */
    private function checks(): array
    {
        return [
            ['APP_ENV es production (entorno actual: '.app()->environment().')', app()->isProduction(), 'Pon APP_ENV=production', true],
            ['APP_DEBUG desactivado (no muestra errores internos)', ! config('app.debug'), 'Pon APP_DEBUG=false', true],
            ['APP_KEY definida (cifrado y firmas)', filled(config('app.key')), 'Ejecuta php artisan key:generate', true],
            ['APP_URL usa HTTPS', Str::startsWith((string) config('app.url'), 'https://'), 'Pon APP_URL=https://tu-dominio', true],
            ['Contraseñas con Argon2id', config('hashing.driver') === 'argon2id', 'Pon HASH_DRIVER=argon2id', true],
            ['Sesión cifrada en el almacén', config('session.encrypt') === true, 'Pon SESSION_ENCRYPT=true', true],
            ['Sesión caduca a los 120 minutos o menos', (int) config('session.lifetime') <= 120, 'Baja SESSION_LIFETIME (60 recomendado)', false],
            ['Cookie de sesión HttpOnly', config('session.http_only') === true, 'Pon SESSION_HTTP_ONLY=true', true],
            ['Cookie de sesión solo por HTTPS', config('session.secure') === true, 'Pon SESSION_SECURE_COOKIE=true', true],
            ['Cookie de sesión SameSite lax o strict', in_array(config('session.same_site'), ['lax', 'strict'], true), 'Pon SESSION_SAME_SITE=lax', true],
            ['Tokens de la API con caducidad', (int) config('sanctum.expiration') > 0, 'Pon SANCTUM_TOKEN_EXPIRATION (minutos)', true],
            ['Política de seguridad de contenido (CSP) aplicada', config('security.csp') === 'enforce', 'Pon SECURITY_CSP=enforce', true],
            ['HSTS activo', config('security.hsts') === true, 'Pon SECURITY_HSTS=true', true],
            ['Registro sin nivel debug', config('logging.channels.single.level') !== 'debug', 'Pon LOG_LEVEL=warning (o info)', false],
            ['.env fuera del repositorio (.gitignore)', $this->envIsIgnored(), 'Agrega .env a .gitignore', true],
        ];
    }

    private function envIsIgnored(): bool
    {
        $gitignore = base_path('.gitignore');

        return is_file($gitignore)
            && in_array('.env', array_map('trim', file($gitignore, FILE_IGNORE_NEW_LINES) ?: []), true);
    }
}
