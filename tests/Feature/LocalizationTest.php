<?php

use App\Models\Classification;
use App\Models\User;
use App\RoleName;
use Illuminate\Support\Arr;

it('traduce al español todos los mensajes del framework', function (string $archivo) {
    $claves = fn (string $idioma): array => Arr::dot(
        Arr::except(trans($archivo, [], $idioma), ['custom', 'attributes'])
    );

    expect(array_diff_key($claves('en'), $claves('es')))->toBeEmpty();
})->with(['auth', 'pagination', 'passwords', 'validation']);

it('muestra los errores de validación en español con el nombre del campo traducido', function () {
    $this->post(route('register.store'), [])
        ->assertSessionHasErrors(['name' => 'El campo nombre es obligatorio.']);
});

it('muestra en español el error de credenciales incorrectas', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
        ->assertSessionHasErrors(['email' => 'Las credenciales no coinciden con nuestros registros.']);
});

it('muestra el resumen de la paginación en español', function () {
    Classification::factory()->count(11)->create();

    $this->actingAs(userWithRole(RoleName::Cliente))
        ->get(route('classifications.index'))
        ->assertOk()
        ->assertSee('Mostrando')
        ->assertDontSee('Showing');
});
