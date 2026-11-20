<?php

use Illuminate\Console\Scheduling\Schedule;

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
    ]);
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

    it('un aviso no grave (sesión demasiado larga) no hace fallar la producción', function () {
        $this->app->detectEnvironment(fn (): string => 'production');
        secureConfiguration();
        config(['session.lifetime' => 600]);

        $this->artisan('security:check')
            ->expectsOutputToContain('Sin fallas')
            ->assertSuccessful();
    });
});
