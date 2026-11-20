<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Inicia sesión por el formulario (así la sesión guarda la huella de la contraseña, como en un navegador real).
 */
function signIn(User $user): void
{
    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
}

describe('política de contraseñas', function () {
    it('exige 12 caracteres, letras y números', function (string $password, bool $accepted) {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
    })->with([
        'muy corta' => ['Clave1abc', false],
        'sin números' => ['solo-letras-largas', false],
        'sin letras' => ['123456789012345', false],
        'válida' => ['clave-segura-123', true],
    ]);
});

describe('sesión web', function () {
    it('cambia el identificador de sesión al iniciar sesión (contra fijación de sesión)', function () {
        $user = User::factory()->create();

        $this->startSession();
        $before = session()->getId();

        signIn($user);

        expect(session()->getId())->not->toBe($before);
    });

    it('cambia el identificador de sesión al registrarse', function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->startSession();
        $before = session()->getId();

        $this->post(route('register.store'), [
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'password' => 'clave-segura-123',
            'password_confirmation' => 'clave-segura-123',
        ])->assertRedirect();

        $this->assertAuthenticated();
        expect(session()->getId())->not->toBe($before);
    });

    it('cierra las demás sesiones cuando la contraseña cambia por otro lado', function () {
        $user = User::factory()->create();

        signIn($user);
        $this->get(route('profile.edit'))->assertOk();

        // Otro dispositivo (o una persona administradora) cambia la contraseña de la cuenta.
        $user->forceFill(['password' => Hash::make('otra-clave-12345')])->save();
        auth()->forgetGuards(); // la petición siguiente vuelve a leer la cuenta, como haría otro servidor

        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    });

    it('al cambiar la contraseña desde el perfil mantiene la sesión actual y revoca los tokens de la API', function () {
        $user = User::factory()->create();
        $user->createToken('api');

        signIn($user);
        $this->get(route('profile.edit'))->assertOk();

        $this->put(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertSessionHasNoErrors();

        $this->get(route('profile.edit'))->assertOk();
        expect($user->tokens()->count())->toBe(0);
    });

    it('al restablecer la contraseña revoca los tokens de la API', function () {
        $user = User::factory()->create();
        $user->createToken('api');

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'otra-clave-segura-1',
            'password_confirmation' => 'otra-clave-segura-1',
        ])->assertSessionHasNoErrors();

        expect($user->tokens()->count())->toBe(0);
    });

    it('la sesión caduca por inactividad y su cookie es HttpOnly', function () {
        expect(config('session.lifetime'))->toBeLessThanOrEqual(120)
            ->and(config('session.http_only'))->toBeTrue()
            ->and(config('session.same_site'))->toBeIn(['lax', 'strict']);
    });
});
