<?php

use App\Models\User;
use App\RoleName;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

/**
 * Eventos del registro de seguridad (OWASP A09) con el nombre indicado.
 *
 * @return list<array{message: string, level: string, context: array<string, mixed>}>
 */
function securityEvents(string $name): array
{
    return array_values(array_filter(securityLog(), fn (array $record): bool => $record['message'] === $name));
}

describe('accesos', function () {
    it('anota el inicio y el cierre de sesión con IP, usuario y ruta', function () {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', 'PruebaNavegador/1.0')
            ->post(route('login.store', ['x' => 'secreto']), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('logout'));

        $login = securityEvents('auth.login');
        expect($login)->toHaveCount(1)
            ->and($login[0]['level'])->toBe('INFO')
            ->and($login[0]['context'])->toMatchArray(['user_id' => $user->id, 'method' => 'POST', 'path' => '/login', 'user_agent' => 'PruebaNavegador/1.0'])
            ->and($login[0]['context']['ip'])->not->toBeEmpty()
            ->and(securityEvents('auth.logout'))->toHaveCount(1);
    });

    it('anota el intento fallido sin guardar la contraseña ni el correo', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'clave-incorrecta-123']);

        $failed = securityEvents('auth.login_failed');
        expect($failed)->toHaveCount(1)
            ->and($failed[0]['level'])->toBe('WARNING')
            ->and($failed[0]['context']['email_hash'])->toHaveLength(12);

        $log = json_encode(securityLog());
        expect($log)->not->toContain('clave-incorrecta-123')->not->toContain($user->email);
    });

    it('anota el bloqueo tras demasiados intentos', function () {
        $user = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'mala']);
        }

        expect(securityEvents('auth.login_failed'))->toHaveCount(5)
            ->and(securityEvents('auth.lockout'))->toHaveCount(1);
    });

    it('anota la emisión y la revocación de tokens de la API sin guardar el token', function () {
        $user = User::factory()->create();

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('token');
        $this->withToken($token)->postJson('/api/logout');

        expect(securityEvents('auth.token_issued'))->toHaveCount(1)
            ->and(securityEvents('auth.token_issued')[0]['context']['user_id'])->toBe($user->id)
            ->and(securityEvents('auth.token_revoked'))->toHaveCount(1)
            ->and(json_encode(securityLog()))->not->toContain($token);
    });
});

describe('contraseñas', function () {
    it('anota el cambio de contraseña', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertSessionHasNoErrors();

        expect(securityEvents('auth.password_changed'))->toHaveCount(1)
            ->and(json_encode(securityLog()))->not->toContain('nueva-clave-123');
    });

    it('anota el restablecimiento de contraseña', function () {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'otra-clave-segura-1',
            'password_confirmation' => 'otra-clave-segura-1',
        ])->assertSessionHasNoErrors();

        expect(securityEvents('auth.password_reset'))->toHaveCount(1)
            ->and(securityEvents('auth.password_reset')[0]['context']['user_id'])->toBe($user->id);
    });

    it('anota el bloqueo por fallar la confirmación de contraseña', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user)->put(route('profile.password.update'), [
                'current_password' => 'equivocada',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ]);
        }

        expect(securityEvents('auth.password_confirm_lockout'))->not->toBeEmpty();
    });
});

describe('accesos denegados', function () {
    it('anota 401 al pedir una página o la API sin sesión', function () {
        $this->get(route('products.index'));
        $this->getJson('/api/products');

        $denied = securityEvents('http.denied');
        expect($denied)->toHaveCount(2)
            ->and($denied[0]['level'])->toBe('WARNING')
            ->and($denied[0]['context'])->toMatchArray(['status' => 401, 'path' => '/products'])
            ->and($denied[1]['context'])->toMatchArray(['status' => 401, 'path' => '/api/products']);
    });

    it('anota 403 con la persona que intentó el acceso y sin los parámetros de la dirección', function () {
        $client = userWithRole(RoleName::Cliente);

        $this->actingAs($client)->get(route('products.create', ['secreto' => 'abc']))->assertForbidden();

        $denied = securityEvents('http.denied');
        expect($denied)->toHaveCount(1)
            ->and($denied[0]['context'])->toMatchArray(['status' => 403, 'user_id' => $client->id, 'path' => '/products/create'])
            ->and(json_encode($denied))->not->toContain('abc');
    });

    it('anota 419 cuando el formulario trae un token CSRF vencido', function () {
        // En las pruebas Laravel no verifica el token CSRF: una ruta que lanza la misma excepción reproduce el 419.
        Route::middleware('web')->post('/prueba-csrf-vencido', fn () => throw new TokenMismatchException);

        $this->post('/prueba-csrf-vencido')->assertStatus(419);

        expect(securityEvents('http.denied')[0]['context'])->toMatchArray(['status' => 419]);
    });

    it('no anota los errores comunes (404 y validación)', function () {
        $this->get('/ruta-que-no-existe')->assertNotFound();
        $this->post(route('login.store'), [])->assertSessionHasErrors();

        expect(securityEvents('http.denied'))->toBeEmpty();
    });
});
