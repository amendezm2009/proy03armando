<?php

use App\Actions\ConfirmPassword;
use App\Models\User;
use App\RoleName;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Notification::fake();
    $this->admin = userWithRole(RoleName::Admin);
    $this->actingAs($this->admin);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function datosParaModal(array $overrides = []): array
{
    return ['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'role' => RoleName::Cliente->value, ...$overrides];
}

describe('el servidor exige la contraseña', function () {
    it('al crear un usuario', function () {
        $this->post(route('users.store'), datosParaModal())->assertSessionHasErrors('current_password');

        expect(User::where('email', 'ana@example.com')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });

    it('al desactivar, reactivar y reenviar la invitación', function () {
        $activo = User::factory()->create();
        $inactivo = User::factory()->inactive()->create();
        $pendiente = User::factory()->invited()->create();

        $this->patch(route('users.deactivate', $activo))->assertSessionHasErrors('current_password');
        $this->patch(route('users.activate', $inactivo))->assertSessionHasErrors('current_password');
        $this->post(route('users.invitation', $pendiente))->assertSessionHasErrors('current_password');

        expect($activo->fresh()->is_active)->toBeTrue()
            ->and($inactivo->fresh()->is_active)->toBeFalse();
        Notification::assertNothingSent();
    });

    it('al cambiar el rol de un usuario', function () {
        $user = User::factory()->create(['email' => 'ana@example.com'])->assignRole(RoleName::Cliente);

        $this->put(route('users.update', $user), datosParaModal(['role' => RoleName::Admin->value]))
            ->assertSessionHasErrors('current_password');

        expect($user->fresh()->getRoleNames()->all())->toBe([RoleName::Cliente->value]);
    });

    it('al cambiar el correo de un usuario', function () {
        $user = User::factory()->create(['email' => 'ana@example.com'])->assignRole(RoleName::Cliente);

        $this->put(route('users.update', $user), datosParaModal(['email' => 'nuevo@example.com']))
            ->assertSessionHasErrors('current_password');

        expect($user->fresh()->email)->toBe('ana@example.com');
    });

    it('pero no al cambiar solo el nombre', function () {
        $user = User::factory()->create(['email' => 'ana@example.com'])->assignRole(RoleName::Cliente);

        $this->put(route('users.update', $user), datosParaModal(['name' => 'Ana María']))
            ->assertSessionHasNoErrors();

        expect($user->fresh()->name)->toBe('Ana María');
    });

    it('y las acciones se hacen con la contraseña correcta', function () {
        $user = User::factory()->create();

        $this->patch(route('users.deactivate', $user), ['current_password' => 'password'])->assertSessionHasNoErrors();

        expect($user->fresh()->is_active)->toBeFalse();
    });
});

describe('contraseña incorrecta', function () {
    it('avisa cuántos intentos quedan, no ejecuta la acción y recuerda cuál era', function () {
        $user = User::factory()->create();

        $this->patch(route('users.deactivate', $user), ['current_password' => 'equivocada'])
            ->assertSessionHasErrors(['current_password' => 'La contraseña es incorrecta. Te quedan 2 intentos.'])
            ->assertSessionHas('confirm_action', ['url' => "/users/{$user->id}/deactivate", 'method' => 'PATCH']);

        expect($user->fresh()->is_active)->toBeTrue();
    });

    it('cierra la sesión al tercer intento fallido sin ejecutar la acción', function () {
        $user = User::factory()->create();

        foreach (range(1, config('auth.confirmation.max_attempts') - 1) as $intento) {
            $this->patch(route('users.deactivate', $user), ['current_password' => 'equivocada']);
        }

        $this->patch(route('users.deactivate', $user), ['current_password' => 'equivocada'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', __('auth.password_lockout'));

        $this->assertGuest();
        expect($user->fresh()->is_active)->toBeTrue();
    });

    it('comparte el contador entre todas las acciones sensibles', function () {
        $user = User::factory()->create();
        $pendiente = User::factory()->invited()->create();

        $this->patch(route('users.deactivate', $user), ['current_password' => 'equivocada']);
        $this->post(route('users.invitation', $pendiente), ['current_password' => 'equivocada']);
        $this->post(route('users.store'), datosParaModal(['current_password' => 'equivocada']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        Notification::assertNothingSent();
    });

    it('reinicia el contador al acertar', function () {
        $user = User::factory()->create();

        $this->patch(route('users.deactivate', $user), ['current_password' => 'equivocada']);
        $this->patch(route('users.deactivate', $user), ['current_password' => 'password']);

        expect(RateLimiter::attempts(ConfirmPassword::key($this->admin)))->toBe(0);
    });

    it('no la envía de vuelta en el formulario', function () {
        $this->from(route('users.create'))
            ->post(route('users.store'), datosParaModal(['current_password' => 'equivocada']))
            ->assertRedirect(route('users.create'));

        $this->get(route('users.create'))->assertDontSee('equivocada');
        expect(session()->getOldInput('current_password'))->toBeNull();
    });
});

describe('modal en las vistas', function () {
    it('el listado abre la modal con botones y ya no usa formularios con confirm()', function () {
        $activo = User::factory()->create();
        $inactivo = User::factory()->inactive()->create();
        $pendiente = User::factory()->invited()->create();

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('data-password-confirm-modal', false)
            ->assertSee('data-confirm-action="'.route('users.deactivate', $activo).'"', false)
            ->assertSee('data-confirm-action="'.route('users.activate', $inactivo).'"', false)
            ->assertSee('data-confirm-action="'.route('users.invitation', $pendiente).'"', false)
            ->assertDontSee('data-confirm="', false)
            ->assertDontSee("confirm('", false);
    });

    it('el alta pide la contraseña siempre y la edición solo si cambia el rol o el correo', function () {
        $user = User::factory()->create();

        $this->get(route('users.create'))
            ->assertSee('data-password-confirm="always"', false)
            ->assertSee('data-password-confirm-modal', false);

        $this->get(route('users.edit', $user))
            ->assertSee('data-password-confirm="changed"', false)
            ->assertSee('data-password-confirm-fields="role,email"', false)
            ->assertSee('data-password-confirm-modal', false);
    });

    it('la modal no rellena ni muestra la contraseña y explica los intentos', function () {
        $this->get(route('users.index'))
            ->assertSee('name="current_password"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('Por seguridad tienes '.config('auth.confirmation.max_attempts').' intentos');
    });

    it('se vuelve a abrir sola tras una contraseña incorrecta, con la misma acción y el error', function () {
        $user = User::factory()->create();

        $this->from(route('users.index'))
            ->patch(route('users.deactivate', $user), [
                'current_password' => 'equivocada',
                'confirm_title' => 'Desactivar usuario',
                'confirm_message' => '¿Desactivar a Ana?',
            ])
            ->assertRedirect(route('users.index'));

        $this->get(route('users.index'))
            ->assertSee('data-reopen="true"', false)
            ->assertSee('action="/users/'.$user->id.'/deactivate"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('Desactivar usuario')
            ->assertSee('¿Desactivar a Ana?')
            ->assertSee('La contraseña es incorrecta. Te quedan 2 intentos.');
    });

    it('no se reabre cuando no hubo error', function () {
        $this->get(route('users.index'))->assertSee('data-reopen="false"', false);
    });

    it('se vuelve a abrir en el formulario de alta con los datos conservados', function () {
        $this->from(route('users.create'))
            ->post(route('users.store'), datosParaModal(['current_password' => 'equivocada']))
            ->assertRedirect(route('users.create'));

        $this->get(route('users.create'))
            ->assertSee('data-reopen="true"', false)
            ->assertSee('value="ana@example.com"', false)
            ->assertSee('La contraseña es incorrecta. Te quedan 2 intentos.');
    });
});
