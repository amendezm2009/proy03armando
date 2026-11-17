<?php

use App\Actions\ConfirmPassword;
use App\Models\User;
use App\RoleName;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    $this->admin = userWithRole(RoleName::Admin);
    Sanctum::actingAs($this->admin);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function datosApiConfirmacion(array $overrides = []): array
{
    return ['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'role' => RoleName::Cliente->value, ...$overrides];
}

describe('la API exige current_password', function () {
    it('al crear, desactivar, reactivar e invitar', function (string $metodo, string $uri) {
        $objetivo = User::factory()->invited()->create();
        $inactivo = User::factory()->inactive()->create();

        $url = str_replace(['{activo}', '{inactivo}'], [$objetivo->id, $inactivo->id], $uri);

        $this->json($metodo, $url, datosApiConfirmacion())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        expect($objetivo->fresh()->is_active)->toBeTrue()
            ->and($inactivo->fresh()->is_active)->toBeFalse();
        Notification::assertNothingSent();
    })->with([
        'alta' => ['POST', '/api/users'],
        'desactivar' => ['PATCH', '/api/users/{activo}/deactivate'],
        'reactivar' => ['PATCH', '/api/users/{inactivo}/activate'],
        'invitación' => ['POST', '/api/users/{activo}/invitation'],
    ]);

    it('al cambiar el rol o el correo, pero no al cambiar solo el nombre', function () {
        $user = User::factory()->create(['email' => 'ana@example.com'])->assignRole(RoleName::Cliente);

        $this->patchJson("/api/users/{$user->id}", ['role' => RoleName::Admin->value])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson("/api/users/{$user->id}", ['email' => 'nuevo@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson("/api/users/{$user->id}", ['name' => 'Ana María'])->assertOk();

        expect($user->fresh())->name->toBe('Ana María')->email->toBe('ana@example.com')
            ->and($user->fresh()->getRoleNames()->all())->toBe([RoleName::Cliente->value]);
    });

    it('y acepta la acción con la contraseña correcta', function () {
        $user = User::factory()->create();

        $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });
});

describe('contraseña incorrecta en la API', function () {
    it('responde 422 con los intentos que quedan y no ejecuta la acción', function () {
        $user = User::factory()->create();

        $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'equivocada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'La contraseña es incorrecta. Te quedan 2 intentos.']);

        expect($user->fresh()->is_active)->toBeTrue();
    });

    it('revoca el token usado y responde 403 en JSON al tercer intento fallido', function () {
        $token = $this->admin->createToken('api');
        $user = User::factory()->create();
        app('auth')->forgetGuards(); // descarta el token transitorio de actingAs: se usa el token real
        $this->withToken($token->plainTextToken);

        foreach (range(1, config('auth.confirmation.max_attempts') - 1) as $intento) {
            $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'equivocada']);
        }

        $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'equivocada'])
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.password_lockout_api'));

        expect(PersonalAccessToken::findToken($token->plainTextToken))->toBeNull()
            ->and($user->fresh()->is_active)->toBeTrue();
    });

    it('conserva los demás tokens de la persona al revocar el usado', function () {
        $usado = $this->admin->createToken('api');
        $otro = $this->admin->createToken('otro-dispositivo');
        $user = User::factory()->create();
        app('auth')->forgetGuards(); // descarta el token transitorio de actingAs: se usa el token real
        $this->withToken($usado->plainTextToken);

        foreach (range(1, config('auth.confirmation.max_attempts')) as $intento) {
            $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'equivocada']);
        }

        expect(PersonalAccessToken::findToken($usado->plainTextToken))->toBeNull()
            ->and(PersonalAccessToken::findToken($otro->plainTextToken))->not->toBeNull();
    });

    it('responde 403 en JSON aunque no haya un token real que revocar', function () {
        $user = User::factory()->create();

        foreach (range(1, config('auth.confirmation.max_attempts') - 1) as $intento) {
            $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'equivocada']);
        }

        $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'equivocada'])->assertForbidden();
    });

    it('comparte el contador con la web y lo reinicia al acertar', function () {
        $user = User::factory()->create();

        $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'equivocada']);
        expect(RateLimiter::attempts(ConfirmPassword::key($this->admin)))->toBe(1);

        $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'password'])->assertOk();
        expect(RateLimiter::attempts(ConfirmPassword::key($this->admin)))->toBe(0);
    });
});
