<?php

use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\PermissionName;
use App\RoleName;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Datos válidos de un usuario nuevo.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function datosDeUsuarioApi(array $overrides = []): array
{
    return ['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'role' => RoleName::Cliente->value, 'current_password' => 'password', ...$overrides];
}

describe('acceso', function () {
    it('devuelve 401 sin token', function () {
        $this->getJson('/api/users')->assertUnauthorized();
        $this->postJson('/api/users', datosDeUsuarioApi())->assertUnauthorized();
    });

    it('devuelve 403 en JSON a quien no es administrador', function (string $rol, string $metodo, string $uri) {
        $objetivo = User::factory()->create();
        Sanctum::actingAs($rol === 'sin rol' ? User::factory()->create() : userWithRole(RoleName::from($rol)));

        $this->json($metodo, str_replace('{id}', (string) $objetivo->id, $uri))
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    })->with(function () {
        foreach (['cliente', 'sin rol'] as $rol) {
            foreach ([
                'listado' => ['GET', '/api/users'],
                'detalle' => ['GET', '/api/users/{id}'],
                'alta' => ['POST', '/api/users'],
                'edición' => ['PUT', '/api/users/{id}'],
                'edición parcial' => ['PATCH', '/api/users/{id}'],
                'desactivar' => ['PATCH', '/api/users/{id}/deactivate'],
                'reactivar' => ['PATCH', '/api/users/{id}/activate'],
                'invitación' => ['POST', '/api/users/{id}/invitation'],
            ] as $accion => [$metodo, $uri]) {
                yield "{$rol}: {$accion}" => [$rol, $metodo, $uri];
            }
        }
    });

    it('no ofrece eliminar usuarios: la ruta no existe', function () {
        Sanctum::actingAs(userWithRole(RoleName::Admin));

        $this->deleteJson('/api/users/'.User::factory()->create()->id)->assertStatus(405);
    });
});

describe('cuenta desactivada', function () {
    it('devuelve 403 en JSON a quien conserva un token tras desactivarse su cuenta', function () {
        Sanctum::actingAs(User::factory()->inactive()->create());

        $this->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.inactive'));
    });

    it('no entrega token a una cuenta desactivada', function () {
        $user = User::factory()->inactive()->create();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    });
});

describe('administración', function () {
    beforeEach(function () {
        Notification::fake();
        $this->admin = userWithRole(RoleName::Admin);
        Sanctum::actingAs($this->admin);
    });

    it('lista usuarios con paginación y sin datos sensibles', function () {
        User::factory()->count(3)->create();

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'roles', 'is_active']], 'links', 'meta'])
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    });

    it('filtra por texto, rol y estado', function () {
        User::factory()->create(['name' => 'Luis Gómez'])->assignRole(RoleName::Cliente);
        User::factory()->inactive()->create(['name' => 'Eva Ruiz']);

        $this->getJson('/api/users?search=Luis')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Luis Gómez');
        $this->getJson('/api/users?role=cliente')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Luis Gómez');
        $this->getJson('/api/users?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
    });

    it('devuelve 422 con un rol o un per_page inválidos', function () {
        $this->getJson('/api/users?role=superadmin&per_page=500')->assertUnprocessable()->assertJsonValidationErrors(['role', 'per_page']);
    });

    it('ya no acepta el rol invitado: no existe como rol', function () {
        $user = User::factory()->create()->assignRole(RoleName::Cliente);

        $this->getJson('/api/users?role=invitado')->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/users', datosDeUsuarioApi(['role' => 'invitado']))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->putJson("/api/users/{$user->id}", datosDeUsuarioApi(['role' => 'invitado']))->assertUnprocessable()->assertJsonValidationErrors('role');
    });

    it('muestra un usuario', function () {
        $user = User::factory()->create()->assignRole(RoleName::Cliente);

        $this->getJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles', ['cliente']);
    });

    it('devuelve 404 si el usuario no existe', function () {
        $this->getJson('/api/users/9999')->assertNotFound();
    });

    it('crea un usuario con rol, sin contraseña y envía la invitación', function () {
        $this->postJson('/api/users', datosDeUsuarioApi())
            ->assertCreated()
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.roles', ['cliente'])
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        Notification::assertSentTo(User::where('email', 'ana@example.com')->firstOrFail(), WelcomeNotification::class);
    });

    it('ignora los campos que no son name, email y role', function () {
        $this->postJson('/api/users', datosDeUsuarioApi(['is_active' => false, 'password' => 'hackeada-123', 'avatar_path' => 'x']))
            ->assertCreated();

        $user = User::where('email', 'ana@example.com')->firstOrFail();

        expect($user->is_active)->toBeTrue()->and($user->avatar_path)->toBeNull();
    });

    it('devuelve 422 con datos inválidos y no envía nada', function () {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/users', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role']);
        $this->postJson('/api/users', datosDeUsuarioApi())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/users', datosDeUsuarioApi(['email' => 'otra@example.com', 'role' => 'superadmin']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        Notification::assertNothingSent();
    });

    it('reemplaza los datos con PUT y cambia solo lo enviado con PATCH', function () {
        $user = User::factory()->create(['name' => 'Luis'])->assignRole(RoleName::Cliente);

        $this->putJson("/api/users/{$user->id}", datosDeUsuarioApi(['role' => RoleName::Admin->value]))
            ->assertOk()
            ->assertJsonPath('data.roles', ['admin']);

        $this->patchJson("/api/users/{$user->id}", ['name' => 'Solo nombre'])->assertOk();

        expect($user->fresh())->name->toBe('Solo nombre')->email->toBe('ana@example.com')
            ->and($user->fresh()->getRoleNames()->all())->toBe(['admin']);
    });

    it('devuelve 422 con PUT cuando falta un campo obligatorio', function () {
        $user = User::factory()->create();

        $this->putJson("/api/users/{$user->id}", ['name' => 'Solo nombre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'role']);
    });

    it('no deja a nadie cambiar su propio rol', function () {
        $this->patchJson("/api/users/{$this->admin->id}", ['role' => RoleName::Cliente->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role' => 'No puedes cambiar tu propio rol.']);

        expect($this->admin->fresh()->hasRole(RoleName::Admin->value))->toBeTrue();
    });

    it('no deja quitar el rol a la única persona administradora activa', function () {
        $gestor = User::factory()->create()->assignRole(RoleName::Cliente)->givePermissionTo(PermissionName::UpdateUsers);
        Sanctum::actingAs($gestor);

        $this->patchJson("/api/users/{$this->admin->id}", ['role' => RoleName::Cliente->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    });

    it('desactiva la cuenta y revoca sus tokens', function () {
        $user = User::factory()->create()->assignRole(RoleName::Cliente);
        $token = $user->createToken('api')->plainTextToken;

        $this->patchJson("/api/users/{$user->id}/deactivate", ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect(PersonalAccessToken::findToken($token))->toBeNull()
            ->and($user->fresh()->is_active)->toBeFalse();
    });

    it('niega desactivarse a sí mismo y desactivar a la única persona administradora activa', function () {
        $this->patchJson("/api/users/{$this->admin->id}/deactivate", ['current_password' => 'password'])->assertForbidden();

        $gestor = User::factory()->create()->assignRole(RoleName::Cliente)->givePermissionTo(PermissionName::DeactivateUsers);
        Sanctum::actingAs($gestor);

        $this->patchJson("/api/users/{$this->admin->id}/deactivate", ['current_password' => 'password'])->assertForbidden();

        expect($this->admin->fresh()->is_active)->toBeTrue();
    });

    it('reactiva una cuenta desactivada y niega reactivar una activa', function () {
        $inactivo = User::factory()->inactive()->create();
        $activo = User::factory()->create();

        $this->patchJson("/api/users/{$inactivo->id}/activate", ['current_password' => 'password'])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson("/api/users/{$activo->id}/activate", ['current_password' => 'password'])->assertForbidden();
    });

    it('reenvía la invitación con 204 y la limita por minuto', function () {
        $user = User::factory()->invited()->create();

        $this->postJson("/api/users/{$user->id}/invitation", ['current_password' => 'password'])->assertNoContent();
        Notification::assertSentTo($user, WelcomeNotification::class);

        foreach (range(2, config('rate_limits.invitation')) as $intento) {
            $this->postJson("/api/users/{$user->id}/invitation", ['current_password' => 'password'])->assertNoContent();
        }

        $this->postJson("/api/users/{$user->id}/invitation", ['current_password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After');
    });

    it('no invita a una cuenta desactivada ni a uno mismo', function () {
        $inactivo = User::factory()->inactive()->invited()->create();

        $this->postJson("/api/users/{$inactivo->id}/invitation", ['current_password' => 'password'])->assertForbidden();
        $this->postJson("/api/users/{$this->admin->id}/invitation", ['current_password' => 'password'])->assertForbidden();
    });

    it('no reenvía a quien ya definió su contraseña ni a quien nunca fue invitado', function () {
        $aceptada = User::factory()->invited()->create(['invitation_accepted_at' => now()]);
        $normal = User::factory()->create();

        $this->postJson("/api/users/{$aceptada->id}/invitation", ['current_password' => 'password'])->assertForbidden();
        $this->postJson("/api/users/{$normal->id}/invitation", ['current_password' => 'password'])->assertForbidden();

        Notification::assertNothingSent();
    });

    it('indica en invitation_pending si la persona aún no define su contraseña', function () {
        $pendiente = User::factory()->invited()->create();
        $aceptada = User::factory()->invited()->create(['invitation_accepted_at' => now()]);
        $normal = User::factory()->create();

        $this->getJson("/api/users/{$pendiente->id}")->assertJsonPath('data.invitation_pending', true);
        $this->getJson("/api/users/{$aceptada->id}")->assertJsonPath('data.invitation_pending', false);
        $this->getJson("/api/users/{$normal->id}")->assertJsonPath('data.invitation_pending', false);
    });

    it('deja la invitación pendiente al crear el usuario', function () {
        $this->postJson('/api/users', datosDeUsuarioApi())
            ->assertCreated()
            ->assertJsonPath('data.invitation_pending', true);
    });
});
