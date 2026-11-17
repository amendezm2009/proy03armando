<?php

use App\Actions\ConfirmPassword;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * Envía el formulario de cambio de contraseña con la contraseña actual indicada.
 */
function enviarCambioDeContrasena(string $actual): TestResponse
{
    return test()->put(route('profile.password.update'), [
        'current_password' => $actual,
        'password' => 'nueva-clave-123',
        'password_confirmation' => 'nueva-clave-123',
    ]);
}

it('avisa cuántos intentos quedan y mantiene la sesión en los primeros fallos', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    enviarCambioDeContrasena('equivocada')
        ->assertSessionHasErrors(['current_password' => 'La contraseña es incorrecta. Te quedan 2 intentos.']);

    enviarCambioDeContrasena('equivocada')
        ->assertSessionHasErrors(['current_password' => 'La contraseña es incorrecta. Te queda 1 intento.']);

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('cierra la sesión al tercer intento fallido y no cambia la contraseña', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    enviarCambioDeContrasena('equivocada');
    enviarCambioDeContrasena('equivocada');

    enviarCambioDeContrasena('equivocada')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', __('auth.password_lockout'));

    $this->assertGuest();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('cierra la sesión con el contador agotado aunque la contraseña sea correcta', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, config('auth.confirmation.max_attempts')) as $intento) {
        RateLimiter::hit(ConfirmPassword::key($user), 900);
    }

    enviarCambioDeContrasena('password')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('reinicia el contador al acertar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    enviarCambioDeContrasena('equivocada');
    enviarCambioDeContrasena('equivocada');

    enviarCambioDeContrasena('password')->assertRedirect(route('profile.edit'));
    expect(Hash::check('nueva-clave-123', $user->fresh()->password))->toBeTrue();

    enviarCambioDeContrasena('equivocada')
        ->assertSessionHasErrors(['current_password' => 'La contraseña es incorrecta. Te quedan 2 intentos.']);
});

it('reinicia el contador al volver a iniciar sesión tras el cierre', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, config('auth.confirmation.max_attempts')) as $intento) {
        enviarCambioDeContrasena('equivocada');
    }
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    enviarCambioDeContrasena('equivocada')
        ->assertSessionHasErrors(['current_password' => 'La contraseña es incorrecta. Te quedan 2 intentos.']);
});

it('cuenta los intentos por usuario', function () {
    $this->actingAs(User::factory()->create());
    enviarCambioDeContrasena('equivocada');
    enviarCambioDeContrasena('equivocada');

    $this->actingAs(User::factory()->create());

    enviarCambioDeContrasena('equivocada')
        ->assertSessionHasErrors(['current_password' => 'La contraseña es incorrecta. Te quedan 2 intentos.']);
});

it('no cuenta intentos cuando la contraseña nueva es inválida', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->put(route('profile.password.update'), [
        'current_password' => 'equivocada',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertSessionHasErrors('password');

    expect(RateLimiter::attempts(ConfirmPassword::key($user)))->toBe(0);
});
