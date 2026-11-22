<?php

use App\RoleName;
use Illuminate\Testing\TestResponse;

/**
 * Entrega la contraseña al informe de seguridad (POST) como la persona que ya inició sesión.
 *
 * @param  array<string, mixed>  $extra
 */
function openSecurityReport(string $password = 'password', array $extra = []): TestResponse
{
    return test()->post(route('security.report.show'), ['current_password' => $password, ...$extra]);
}

describe('acceso', function () {
    it('manda a iniciar sesión a quien no la tiene', function () {
        $this->get(route('security.report'))->assertRedirect(route('login'));
        $this->post(route('security.report.show'), ['current_password' => 'password'])->assertRedirect(route('login'));
    });

    it('rechaza al cliente', function () {
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('security.report'))->assertForbidden();
        openSecurityReport()->assertForbidden();
    });

    it('al administrador le muestra el formulario, no el informe, y el enlace del menú', function () {
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->get(route('security.report'))
            ->assertOk()
            ->assertSee('Ver informe')
            ->assertSee('Tu contraseña')
            ->assertDontSee('Entorno:')
            ->assertSee(route('security.report'));
    });

    it('el enlace del menú no aparece al cliente', function () {
        $this->actingAs(userWithRole(RoleName::Cliente))->get(route('client.dashboard'))
            ->assertDontSee(route('security.report'));
    });
});

describe('informe', function () {
    it('pide la contraseña: con una incorrecta no muestra nada ni lo anota como consulta', function () {
        $this->actingAs(userWithRole(RoleName::Admin));

        openSecurityReport('equivocada')->assertSessionHasErrors('current_password');

        expect(array_filter(securityLog(), fn (array $record): bool => $record['message'] === 'security.report_viewed'))->toBeEmpty();
    });

    it('con la contraseña correcta muestra las revisiones sin valores de configuración y lo anota', function () {
        $admin = userWithRole(RoleName::Admin);
        $this->actingAs($admin);

        $response = openSecurityReport()
            ->assertOk()
            ->assertSee('Entorno:')
            ->assertSee('APP_ENV es production')
            ->assertSee('APP_DEBUG desactivado')
            ->assertSee('Cookie de sesión HttpOnly')
            ->assertDontSee(config('app.key'));

        expect($response->headers->get('Cache-Control'))->toContain('no-store');

        $viewed = array_values(array_filter(securityLog(), fn (array $record): bool => $record['message'] === 'security.report_viewed'));
        expect($viewed)->toHaveCount(1)
            ->and($viewed[0]['context']['user_id'])->toBe($admin->id);
    });

    it('"Evaluar como producción" convierte los avisos graves en fallas', function () {
        $this->actingAs(userWithRole(RoleName::Admin));
        config(['app.debug' => true]);

        openSecurityReport()->assertSee('AVISO')->assertDontSee('FALLA');
        openSecurityReport(extra: ['strict' => '1'])->assertSee('FALLA')->assertSee('evaluado como producción');
    });

    it('no guarda el informe: volver a la página pide la contraseña otra vez', function () {
        $this->actingAs(userWithRole(RoleName::Admin));

        openSecurityReport()->assertSee('Entorno:');

        $this->get(route('security.report'))->assertDontSee('Entorno:')->assertSee('Ver informe');
    });

    it('cierra la sesión tras demasiadas contraseñas incorrectas', function () {
        $this->actingAs(userWithRole(RoleName::Admin));

        foreach (range(1, 3) as $attempt) {
            openSecurityReport('equivocada');
        }

        $this->assertGuest();
    });
});
