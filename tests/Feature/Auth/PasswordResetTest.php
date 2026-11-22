<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

describe('solicitud del enlace', function () {
    it('muestra el formulario', function () {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Recuperar contraseña');
    });

    it('envía el enlace al correo de un usuario registrado', function () {
        Notification::fake();
        $user = User::factory()->create();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPassword::class);
    });

    it('responde igual y no envía nada cuando el correo no está registrado', function () {
        Notification::fake();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'nadie@example.com'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    });

    it('rechaza un correo con formato inválido', function () {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'no-es-un-correo'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    });

    it('redacta el correo en español', function () {
        $user = User::factory()->create();

        $mail = (new ResetPassword('token-de-prueba'))->toMail($user);

        expect($mail->subject)->toBe('Restablecimiento de contraseña')
            ->and($mail->actionText)->toBe('Restablecer contraseña');
    });
});

describe('restablecimiento', function () {
    it('muestra el formulario con el token y el correo del enlace', function () {
        $this->get(route('password.reset', ['token' => 'token-de-prueba', 'email' => 'ana@example.com']))
            ->assertOk()
            ->assertSee('token-de-prueba')
            ->assertSee('ana@example.com');
    });

    it('cambia la contraseña con un token válido', function () {
        $user = User::factory()->create();

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        expect(Hash::check('nueva-clave-123', $user->refresh()->password))->toBeTrue();
        $this->assertGuest();
    });

    it('rechaza un token inválido y conserva la contraseña', function () {
        $user = User::factory()->create();

        $this->post(route('password.store'), [
            'token' => 'token-falso',
            'email' => $user->email,
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertSessionHasErrors('email');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    it('rechaza una confirmación distinta y conserva la contraseña', function () {
        $user = User::factory()->create();

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'otra-clave-456',
        ])->assertSessionHasErrors('password');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    it('no permite reutilizar un token ya usado', function () {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $datos = fn (string $clave): array => [
            'token' => $token,
            'email' => $user->email,
            'password' => $clave,
            'password_confirmation' => $clave,
        ];

        $this->post(route('password.store'), $datos('nueva-clave-123'))->assertRedirect(route('login'));
        $this->post(route('password.store'), $datos('segunda-clave-456'))->assertSessionHasErrors('email');

        expect(Hash::check('nueva-clave-123', $user->refresh()->password))->toBeTrue();
    });
});
