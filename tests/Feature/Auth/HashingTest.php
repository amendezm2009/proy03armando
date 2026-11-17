<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('cifra las contraseñas nuevas con Argon2id', function () {
    expect(password_get_info(Hash::make('secreto-seguro-1'))['algoName'])->toBe('argon2id');
});

it('guarda con Argon2id la contraseña de un usuario nuevo', function () {
    $user = User::factory()->create();

    expect(password_get_info($user->password)['algoName'])->toBe('argon2id');
});

it('convierte a Argon2id una contraseña bcrypt al iniciar sesión', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => password_hash('secreto-seguro-1', PASSWORD_BCRYPT)]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'secreto-seguro-1'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(password_get_info($user->fresh()->password)['algoName'])->toBe('argon2id');
});

it('rechaza una contraseña incorrecta sin tocar el hash bcrypt', function () {
    $user = User::factory()->create();
    $hash = password_hash('secreto-seguro-1', PASSWORD_BCRYPT);
    DB::table('users')->where('id', $user->id)->update(['password' => $hash]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->fresh()->password)->toBe($hash);
});
