<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

describe('acceso', function () {
    it('envía a los invitados al login', function () {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    });

    it('no permite a un invitado modificar un perfil', function () {
        $this->patch(route('profile.update'), ['name' => 'Intruso', 'email' => 'intruso@example.com'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'intruso@example.com']);
    });

    it('muestra los datos del usuario con sesión', function () {
        $user = User::factory()->create(['name' => 'Ana Pérez', 'email' => 'ana@example.com']);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('ana@example.com');
    });
});

describe('datos del perfil', function () {
    it('actualiza el nombre y el correo', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Ana Pérez', 'email' => 'ana@example.com'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        expect($user->refresh())
            ->name->toBe('Ana Pérez')
            ->email->toBe('ana@example.com');
    });

    it('permite guardar conservando el correo propio', function () {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Ana Pérez', 'email' => 'ana@example.com'])
            ->assertSessionHasNoErrors();

        expect($user->refresh()->name)->toBe('Ana Pérez');
    });

    it('rechaza el correo de otro usuario', function () {
        User::factory()->create(['email' => 'ocupado@example.com']);
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Ana Pérez', 'email' => 'ocupado@example.com'])
            ->assertSessionHasErrors('email');

        expect($user->refresh()->email)->toBe('ana@example.com');
    });

    it('exige nombre y correo', function () {
        $this->actingAs(User::factory()->create())
            ->patch(route('profile.update'), [])
            ->assertSessionHasErrors(['name', 'email']);
    });
});

describe('cambio de contraseña', function () {
    it('cambia la contraseña con la actual correcta', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        expect(Hash::check('nueva-clave-123', $user->refresh()->password))->toBeTrue();
    });

    it('rechaza una contraseña actual incorrecta y conserva la anterior', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.password.update'), [
                'current_password' => 'equivocada',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertSessionHasErrors('current_password');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    it('rechaza una confirmación distinta y conserva la anterior', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'otra-clave-456',
            ])
            ->assertSessionHasErrors('password');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    it('no permite a un invitado cambiar una contraseña', function () {
        $this->put(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertRedirect(route('login'));
    });
});
