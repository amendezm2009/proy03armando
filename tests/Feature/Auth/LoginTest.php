<?php

use App\Models\User;

describe('inicio de sesión', function () {
    it('muestra el formulario', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Iniciar sesión');
    });

    it('inicia sesión con credenciales válidas', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    });

    it('rechaza una contraseña incorrecta', function () {
        $user = User::factory()->create();

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    it('exige correo y contraseña', function () {
        $this->post(route('login.store'), [])
            ->assertSessionHasErrors(['email', 'password']);
    });

    it('bloquea el acceso tras cinco intentos fallidos', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $intento) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    it('redirige al inicio a quien ya tiene sesión', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('home'));
    });
});

describe('rutas protegidas', function () {
    it('envía a los invitados al login', function () {
        $this->get(route('classifications.index'))
            ->assertRedirect(route('login'));
    });

    it('vuelve a la página solicitada después de iniciar sesión', function () {
        $user = User::factory()->create();

        $this->get(route('classifications.create'))->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('classifications.create'));
    });
});

describe('cierre de sesión', function () {
    it('cierra la sesión y vuelve al inicio', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    });

    it('no permite cerrar sesión a un invitado', function () {
        $this->post(route('logout'))
            ->assertRedirect(route('login'));
    });

    it('impide que el navegador guarde en caché las páginas protegidas', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('classifications.index'))
            ->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});

