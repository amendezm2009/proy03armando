<?php

use App\Models\User;

it('envía no-store en las páginas públicas para invitados', function (string $ruta) {
    $response = $this->get(route($ruta))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['portada' => 'home', 'login' => 'login', 'registro' => 'register']);

it('envía no-store en la portada cuando hay sesión, porque muestra los datos del usuario', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('envía no-store en la portada a la que se vuelve tras cerrar la sesión', function () {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('home'));

    $response = $this->get(route('home'))->assertOk();

    $this->assertGuest();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('marca en el HTML si la página se generó con sesión, para que "atrás" la vuelva a pedir', function () {
    $this->get(route('home'))->assertSee('data-authenticated="false"', false);

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee('data-authenticated="true"', false);
});

it('no añade no-store a las respuestas de la API', function () {
    $response = $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/user')->assertOk();

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});
