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
            ['Claves de firma fuera del repositorio (.gitignore)', $this->signingKeysAreIgnored(), 'Agrega «*_keys/» y «release-private.key» a .gitignore', true],
            [
                app()->isProduction() ? 'Clave privada de firma ausente del servidor' : 'Clave privada de firma fuera de la carpeta del proyecto',
                $this->signingKeyIsSafe(),
                app()->isProduction() ? 'Quita RELEASE_SIGNING_KEY_FILE del .env y borra la clave privada del servidor: solo debe existir en desarrollo' : 'Guarda la clave privada fuera del proyecto (php artisan release:keygen) y apunta RELEASE_SIGNING_KEY_FILE a ella',
                true,
            ],
            ['Clave pública de las versiones presente y válida', $this->hasPublicKey(), 'Crea el par con php artisan release:keygen y haz commit de config/release-public.key', false],
            ['HEALTH_TOKEN definido con 32 caracteres o más', mb_strlen((string) config('release.health_token')) >= 32, 'Define HEALTH_TOKEN con 32 caracteres o más (por ejemplo, php -r "echo bin2hex(random_bytes(24));")', false],
        ];
    }

    /**
     * @return list<string> Líneas del .gitignore sin espacios, o vacío si no existe.
     */
    private function gitignoreLines(): array
    {
        $gitignore = base_path('.gitignore');

        return is_file($gitignore) ? array_map('trim', file($gitignore, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /**
     * En un servidor al que se subió el proyecto sin control de versiones (sin .git ni .gitignore) nada puede
     * llegar a un repositorio, así que las revisiones del .gitignore no aplican y se dan por buenas.
     */
    private function hasNoRepository(): bool
    {
        return ! file_exists(base_path('.git')) && ! is_file(base_path('.gitignore'));
    }

    private function envIsIgnored(): bool
    {
        return $this->hasNoRepository() || in_array('.env', $this->gitignoreLines(), true);
    }

    private function signingKeysAreIgnored(): bool
    {
        if ($this->hasNoRepository()) {
            return true;
        }

        $lines = $this->gitignoreLines();

        return count(array_intersect($lines, ['*_keys/', '/*_keys/', '*_keys', '/*_keys'])) > 0
            && count(array_intersect($lines, ['release-private.key', '*release-private.key', '*-release-private.key', '*.key'])) > 0;
    }

    /**
     * En producción la clave privada no debe estar en el servidor (basta con no configurarla); en desarrollo debe
     * estar fuera de la carpeta del proyecto, para que nunca llegue al repositorio ni a un paquete.
     */
    private function signingKeyIsSafe(): bool
    {
        $path = (string) config('release.signing_key_file');

        if ($path === '') {
            return true;
        }

        if (app()->isProduction()) {
            return false;
        }

        $normalize = fn (string $value): string => strtolower(rtrim(str_replace('\\', '/', $value), '/')).'/';
        $directory = realpath(dirname($path)) ?: dirname($path);

        return ! str_starts_with($normalize($directory), $normalize((string) realpath(base_path())));
    }

    private function hasPublicKey(): bool
    {
        $raw = base64_decode(trim((string) config('release.public_key')), true);

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }
}
