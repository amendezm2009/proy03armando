<?php

use App\RoleName;

/**
 * En producción, con errores graves de configuración el público y los clientes ven «mantenimiento» y los
 * administradores entran a todo; al corregirlos, el sitio se abre solo.
 */
function productionWithConfigurationErrors(): void
{
    app()->detectEnvironment(fn (): string => 'production');
    healthyConfiguration();
    config(['app.debug' => true]);
}

function productionHealthy(): void
{
    app()->detectEnvironment(fn (): string => 'production');
    healthyConfiguration();
}

function healthyConfiguration(): void
{
    config([
        'security.block_on_errors' => true,
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
        'release.signing_key_file' => null,
    ]);
}

describe('con errores graves de configuración en producción', function () {
    it('el público ve «mantenimiento» (503) que se recarga sola hacia la misma página que pedía', function () {
        productionWithConfigurationErrors();

        $response = $this->get(route('catalog.index', ['page' => 2]));

        $response->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertSee('Sitio en mantenimiento')
            ->assertSee('Reintentar ahora')
            ->assertSee('content="30;url='.route('catalog.index', ['page' => 2]).'"', false)
            ->assertDontSee('APP_DEBUG');
    });

    it('un envío de formulario bloqueado no se repite al recargar: la página vuelve al inicio', function () {
        productionWithConfigurationErrors();

        // En producción la verificación CSRF ya no se omite (solo se omite con el entorno «testing»): se envía un token.
        $this->withSession(['_token' => 'token-de-prueba'])
            ->post(route('register.store'), ['_token' => 'token-de-prueba', 'name' => 'X'])
            ->assertStatus(503)
            ->assertSee('content="30;url='.url('/').'"', false);
    });

    it('siguen disponibles el acceso, /health y la API responde 503 en JSON', function () {
        productionWithConfigurationErrors();

        $this->get(route('login'))->assertOk();
        $this->get(route('health'))->assertOk();
        $this->postJson(route('api.login'), [])->assertStatus(503)->assertHeader('Retry-After', '30')->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'mantenimiento'));
    });

    it('los clientes ven el mantenimiento y los administradores entran a todo con un aviso', function () {
        // Los usuarios se crean antes de pasar a «producción»: el seeder de roles pide confirmación en ese entorno.
        $cliente = userWithRole(RoleName::Cliente);
        $admin = userWithRole(RoleName::Admin);
        productionWithConfigurationErrors();

        $this->actingAs($cliente)
            ->get(route('client.dashboard'))
            ->assertStatus(503)
            ->assertSee('Sitio en mantenimiento');

        $this->actingAs($admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('ven la página de')
            ->assertSee('mantenimiento')
            ->assertSee(route('security.report'));

        $this->get(route('security.report'))->assertOk();
        $this->get(route('updates.index'))->assertOk();
        $this->get(route('catalog.index'))->assertOk()->assertSee('ven la página de');
    });

    it('al corregir el error el sitio se abre solo, sin aviso, en la misma dirección', function () {
        $admin = userWithRole(RoleName::Admin);
        productionWithConfigurationErrors();
        $this->get(route('catalog.index'))->assertStatus(503);

        healthyConfiguration();

        $this->get(route('catalog.index'))->assertOk()->assertDontSee('Sitio en mantenimiento');
        $this->actingAs($admin)->get(route('products.index'))->assertOk()->assertDontSee('ven la página de');
        $this->postJson(route('api.login'), [])->assertStatus(422);
    });
});

describe('cuando no debe actuar', function () {
    it('con la configuración sana en producción no bloquea a nadie', function () {
        productionHealthy();

        $this->get(route('catalog.index'))->assertOk();
    });

    it('fuera de producción nunca bloquea, aunque la configuración tenga errores', function () {
        healthyConfiguration();
        config(['app.debug' => true]);

        $this->get(route('catalog.index'))->assertOk();
    });

    it('se puede desactivar con SECURITY_BLOCK_ON_ERRORS=false', function () {
        productionWithConfigurationErrors();
        config(['security.block_on_errors' => false]);

        $this->get(route('catalog.index'))->assertOk();
    });

    it('una falla no grave no bloquea (solo las que marcan FALLA en producción)', function () {
        productionHealthy();
        config(['session.lifetime' => 600, 'release.health_token' => null]);

        $this->get(route('catalog.index'))->assertOk();
    });
});
