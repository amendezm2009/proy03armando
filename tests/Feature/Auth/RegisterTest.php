<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function datosDeRegistro(array $overrides = []): array
{
    return [
        'name' => 'Ana Pérez',
        'email' => 'ana@example.com',
        'password' => 'secreto-seguro-1',
        'password_confirmation' => 'secreto-seguro-1',
        ...$overrides,
    ];
}

it('muestra el formulario de registro', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Crear cuenta');
});

it('registra al usuario e inicia su sesión', function () {
    $this->post(route('register.store'), datosDeRegistro())
        ->assertRedirect(route('home'));

    $user = User::where('email', 'ana@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('secreto-seguro-1', $user->password))->toBeTrue();
});

it('rechaza un correo ya registrado', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->post(route('register.store'), datosDeRegistro())
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rechaza una confirmación de contraseña distinta', function () {
    $this->post(route('register.store'), datosDeRegistro(['password_confirmation' => 'otra-cosa-123']))
        ->assertSessionHasErrors('password');

    $this->assertDatabaseCount('users', 0);
});

it('rechaza una contraseña de menos de 8 caracteres', function () {
    $this->post(route('register.store'), datosDeRegistro(['password' => 'corta', 'password_confirmation' => 'corta']))
        ->assertSessionHasErrors('password');
});

it('exige nombre, correo y contraseña', function () {
    $this->post(route('register.store'), [])
        ->assertSessionHasErrors(['name', 'email', 'password']);
});
