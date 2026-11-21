<?php

use App\Actions\RunSecurityChecks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

/**
 * Configuración segura completa: cada valor que revisa php artisan security:check.
 */
function secureConfiguration(): void
{
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app.url' => 'https://tienda.example.com',
        'hashing.driver' => 'argon2id',
        'session.encrypt' => true,
        'session.lifetime' => 60,
        'session.http_only' => true,
        'session.secure' => true,
        'session.same_site' => 'lax',
        'sanctum.expiration' => 480,
        'security.csp' => 'enforce',
        'security.hsts' => true,
        'logging.channels.single.level' => 'warning',
        'release.public_key' => base64_encode(str_repeat('p', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        'release.health_token' => str_repeat('t', 32),
        'release.signing_key_file' => null,
    ]);
}

/**
 * @return array{status: string, label: string, fix: string}
 */
function securityCheckRow(string $labelStart, bool $strict = false): array
{
    return app(RunSecurityChecks::class)->handle($strict)->first(fn (array $row): bool => str_starts_with($row['label'], $labelStart));
}

describe('security:check', function () {
    it('termina bien en producción con la configuración segura', function () {
        $this->app->detectEnvironment(fn (): string => 'production');
        secureConfiguration();

        $this->artisan('security:check')
            ->expectsOutputToContain('Todo en orden')
            ->assertSuccessful();
    });

    it('falla en producción cuando algo grave está mal puesto', function () {
        $this->app->detectEnvironment(fn (): string => 'production');
        secureConfiguration();
        config(['app.debug' => true, 'session.secure' => false, 'security.csp' => 'off']);

        $this->artisan('security:check')
            ->expectsOutputToContain('FALLA')
            ->expectsOutputToContain('graves fallan en producción')
            ->assertFailed();
    });

    it('fuera de producción solo avisa', function () {
        secureConfiguration();
        config(['app.debug' => true, 'app.url' => 'http://localhost:8000']);

        $this->artisan('security:check')
            ->expectsOutputToContain('AVISO')
            ->assertSuccessful();
    });

    it('--strict evalúa como producción aunque el entorno no lo sea', function () {
        secureConfiguration();
        config(['app.debug' => true]);

        $this->artisan('security:check')->assertSuccessful();
        $this->artisan('security:check --strict')->assertFailed();
    });

    it('muestra la fila de APP_ENV y con --strict un entorno que no es production falla', function () {
        secureConfiguration();

        $this->artisan('security:check')->expectsOutputToContain('APP_ENV es production (entorno actual: testing)')->assertSuccessful();
        $this->artisan('security:check --strict')->expectsOutputToContain('- APP_ENV es production (entorno actual: testing): Pon APP_ENV=production')->assertFailed();
    });

    it('al final nombra cada falla grave con su corrección', function () {
        $this->app->detectEnvironment(fn (): string => 'production');
        secureConfiguration();
        config(['app.debug' => true, 'app.url' => 'http://localhost']);

        $this->artisan('security:check --strict')
            ->expectsOutputToContain('2 revisión(es) graves fallan en producción:')
            ->expectsOutputToContain('- APP_DEBUG desactivado (no muestra errores internos): Pon APP_DEBUG=false')
            ->expectsOutputToContain('- APP_URL usa HTTPS: Pon APP_URL=https://tu-dominio')
            ->assertFailed();
    });

    it('--log anota el resultado en el registro de seguridad sin valores de configuración', function () {
        $this->app->detectEnvironment(fn (): string => 'production');
        secureConfiguration();
        config(['app.debug' => true]);

        $this->artisan('security:check --strict --log')->assertFailed();

        $events = array_values(array_filter(securityLog(), fn (array $record): bool => $record['message'] === 'security.check'));

        expect($events)->toHaveCount(1)
            ->and($events[0]['level'])->toBe('WARNING')
            ->and($events[0]['context']['failures'])->toBe(['APP_DEBUG desactivado (no muestra errores internos)'])
            ->and(json_encode($events))->not->toContain(config('app.key'));
    });

    it('la revisión diaria está programada', function () {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode('|');

        expect($commands)->toContain('security:check --log');
    });

    describe('claves de firma y comprobación de salud', function () {
        it('con la configuración segura y el .gitignore del proyecto, las filas nuevas están en orden', function () {
            secureConfiguration();

            expect(securityCheckRow('Claves de firma fuera del repositorio')['status'])->toBe('OK')
                ->and(securityCheckRow('Clave privada de firma')['status'])->toBe('OK')
                ->and(securityCheckRow('Clave pública de las versiones')['status'])->toBe('OK')
                ->and(securityCheckRow('HEALTH_TOKEN')['status'])->toBe('OK');
        });

        it('en producción la clave privada no puede estar configurada en el servidor', function () {
            $this->app->detectEnvironment(fn (): string => 'production');
            secureConfiguration();
            config(['release.signing_key_file' => '/srv/claves/20261120-20-53-release-private.key']);

            $row = securityCheckRow('Clave privada de firma');

            expect($row['status'])->toBe('FALLA')->and($row['label'])->toContain('ausente del servidor');

            $this->artisan('security:check')->expectsOutputToContain('- Clave privada de firma ausente del servidor: Quita RELEASE_SIGNING_KEY_FILE')->assertFailed();
        });

        it('en desarrollo la clave privada debe estar fuera de la carpeta del proyecto', function () {
            secureConfiguration();

            config(['release.signing_key_file' => base_path('storage/20261120-20-53-release-private.key')]);
            expect(securityCheckRow('Clave privada de firma', strict: true)['status'])->toBe('FALLA')
                ->and(securityCheckRow('Clave privada de firma')['status'])->toBe('AVISO');

            config(['release.signing_key_file' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'proy_keys'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'20261120-20-53-release-private.key']);
            expect(securityCheckRow('Clave privada de firma', strict: true)['status'])->toBe('OK');
        });

        it('avisa si falta la clave pública o es inválida', function () {
            secureConfiguration();

            foreach ([null, 'no-es-una-clave', base64_encode('corta')] as $invalid) {
                config(['release.public_key' => $invalid]);

                expect(securityCheckRow('Clave pública de las versiones', strict: true)['status'])->toBe('AVISO');
            }
        });

        it('exige un HEALTH_TOKEN de 32 caracteres o más', function () {
            secureConfiguration();

            config(['release.health_token' => str_repeat('t', 31)]);
            expect(securityCheckRow('HEALTH_TOKEN')['status'])->toBe('AVISO');

            config(['release.health_token' => null]);
            expect(securityCheckRow('HEALTH_TOKEN')['status'])->toBe('AVISO');

            config(['release.health_token' => str_repeat('t', 32)]);
            expect(securityCheckRow('HEALTH_TOKEN')['status'])->toBe('OK');
        });

        it('falla si el .gitignore no protege las claves de firma', function () {
            secureConfiguration();
            $original = base_path();
            $project = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proy03-gitignore-'.bin2hex(random_bytes(4));
            File::ensureDirectoryExists($project);

            try {
                $this->app->setBasePath($project);

                file_put_contents($project.'/.gitignore', "/vendor\n.env\n");
                expect(securityCheckRow('Claves de firma fuera del repositorio', strict: true)['status'])->toBe('FALLA');

                file_put_contents($project.'/.gitignore', "/vendor\n.env\n/*_keys/\n");
                expect(securityCheckRow('Claves de firma fuera del repositorio', strict: true)['status'])->toBe('FALLA');

                file_put_contents($project.'/.gitignore', "/vendor\n.env\n/*_keys/\nrelease-private.key\n");
                expect(securityCheckRow('Claves de firma fuera del repositorio', strict: true)['status'])->toBe('OK');
            } finally {
                $this->app->setBasePath($original);
                File::deleteDirectory($project);
            }
        });
    });

    it('un aviso no grave (sesión demasiado larga) no hace fallar la producción', function () {
        $this->app->detectEnvironment(fn (): string => 'production');
        secureConfiguration();
        config(['session.lifetime' => 600]);

        $this->artisan('security:check')
            ->expectsOutputToContain('Sin fallas')
            ->assertSuccessful();
    });
});
