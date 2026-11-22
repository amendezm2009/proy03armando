<?php

/**
 * Repite una petición hasta agotar el límite del limitador indicado.
 *
 * @param  array<string, mixed>  $data
 */
function agotarLimite(string $limiter, string $uri, array $data = []): void
{
    foreach (range(1, config("rate_limits.{$limiter}")) as $intento) {
        test()->post($uri, [...$data, 'email' => "persona{$intento}@example.com"]);
    }
}

it('limita por IP el inicio de sesión aunque cambie el correo', function () {
    agotarLimite('login', route('login.store'), ['password' => 'incorrecta']);

    $this->post(route('login.store'), ['email' => 'otra@example.com', 'password' => 'incorrecta'])
        ->assertTooManyRequests()
        ->assertSee('Demasiados intentos');
});

it('limita por IP el registro', function () {
    agotarLimite('register', route('register.store'));

    $this->post(route('register.store'), [])->assertTooManyRequests();

    $this->assertDatabaseCount('users', 0);
});

it('limita por IP la solicitud de enlaces de recuperación', function () {
    agotarLimite('password', route('password.email'));

    $this->post(route('password.email'), ['email' => 'otra@example.com'])->assertTooManyRequests();
});

it('comparte el límite entre solicitar y restablecer la contraseña', function () {
    agotarLimite('password', route('password.email'));

    $this->post(route('password.store'), [])->assertTooManyRequests();
});

it('limita por IP el inicio de sesión de la API y responde en JSON', function () {
    foreach (range(1, config('rate_limits.login')) as $intento) {
        $this->postJson('/api/login', ['email' => "persona{$intento}@example.com", 'password' => 'incorrecta']);
    }

    $this->postJson('/api/login', ['email' => 'otra@example.com', 'password' => 'incorrecta'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['message']);
});

it('vuelve a aceptar intentos de inicio de sesión al pasar un minuto', function () {
    agotarLimite('login', route('login.store'), ['password' => 'incorrecta']);

    $this->travel(61)->seconds();

    $this->post(route('login.store'), ['email' => 'otra@example.com', 'password' => 'incorrecta'])
        ->assertSessionHasErrors('email');
});
