<?php

use App\Models\Classification;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

describe('inicio de sesión', function () {
    it('devuelve 200 con el token y los datos del usuario', function () {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonMissingPath('data.password');

        expect(PersonalAccessToken::findToken($response->json('token'))?->tokenable_id)->toBe($user->id);
    });

    it('devuelve 422 con una contraseña incorrecta y no emite token', function () {
        $user = User::factory()->create();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('devuelve 422 cuando faltan el correo y la contraseña', function () {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    });

    it('devuelve 422 tras cinco intentos fallidos aunque la contraseña sea correcta', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $intento) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'incorrecta']);
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    });
});

describe('usuario autenticado', function () {
    it('devuelve 200 con los datos del dueño del token', function () {
        $user = User::factory()->create();

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password');
    });

    it('devuelve 401 con un token inválido', function () {
        $this->withToken('token-falso')
            ->getJson('/api/user')
            ->assertUnauthorized();
    });
});

describe('cierre de sesión', function () {
    it('devuelve 204 y revoca solo el token usado', function () {
        $user = User::factory()->create();
        $tokenUsado = $user->createToken('api')->plainTextToken;
        $otroToken = $user->createToken('otro-dispositivo')->plainTextToken;

        $this->withToken($tokenUsado)
            ->postJson('/api/logout')
            ->assertNoContent();

        expect(PersonalAccessToken::findToken($tokenUsado))->toBeNull()
            ->and(PersonalAccessToken::findToken($otroToken))->not->toBeNull();
    });

    it('devuelve 401 sin token', function () {
        $this->postJson('/api/logout')->assertUnauthorized();
    });
});

describe('rutas protegidas', function () {
    it('devuelve 401 sin token', function (string $method, string $uri) {
        $classification = Classification::factory()->create();

        $this->json($method, str_replace('{id}', (string) $classification->id, $uri))
            ->assertUnauthorized();
    })->with([
        'listado' => ['GET', '/api/classifications'],
        'creación' => ['POST', '/api/classifications'],
        'detalle' => ['GET', '/api/classifications/{id}'],
        'actualización' => ['PUT', '/api/classifications/{id}'],
        'eliminación' => ['DELETE', '/api/classifications/{id}'],
    ]);

    it('no elimina una clasificación sin token', function () {
        $classification = Classification::factory()->create();

        $this->deleteJson("/api/classifications/{$classification->id}")->assertUnauthorized();

        $this->assertModelExists($classification);
    });

    it('devuelve 200 en el listado con un token válido', function () {
        $user = User::factory()->create();

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/classifications')
            ->assertOk();
    });
});
