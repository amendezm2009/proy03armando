<?php

// test('example', function () {
//     $response = $this->get('/');

//     $response->assertStatus(200);
// });
use App\RoleName;

beforeEach(fn () => $this->actingAs(userWithRole(RoleName::Admin)));

it('muestra el asterisco cuando el campo es obligatorio', function () {
    $this->blade('<x-form.label for="name" required>Nombre</x-form.label>')
        ->assertSee('Nombre')
        ->assertSee('*', false);
});

it('no muestra el asterisco cuando el campo es opcional', function () {
    $this->blade('<x-form.label for="description">Descripción</x-form.label>')
        ->assertSee('Descripción')
        ->assertDontSee('*', false);
});

it('muestra la leyenda de campo obligatorio en el formulario de clasificaciones', function () {
    $this->get(route('classifications.create'))->assertOk()->assertSee('Campo obligatorio');
});
