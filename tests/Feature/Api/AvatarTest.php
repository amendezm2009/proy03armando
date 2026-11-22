<?php

use App\Models\User;
use App\RoleName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('local'));

/**
 * Guarda una foto de prueba para la persona y devuelve su ruta en el disco.
 */
function conFotoApi(User $user): string
{
    $path = 'avatars/'.str_repeat('a', 40).'.webp';
    Storage::disk('local')->put($path, 'contenido-de-prueba');
    $user->forceFill(['avatar_path' => $path])->save();

    return $path;
}

describe('subir la foto propia', function () {
    it('devuelve 401 sin token', function () {
        $this->postJson('/api/user/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)])->assertUnauthorized();
    });

    it('guarda la foto y devuelve la dirección en avatar_url', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $respuesta = $this->postJson('/api/user/avatar', ['avatar' => UploadedFile::fake()->image('a.png', 500, 300)])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $path = $user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);
        expect($respuesta->json('data.avatar_url'))->toBe(route('avatars.show', basename($path)))
            ->and(getimagesizefromstring(Storage::disk('local')->get($path))['mime'])->toBe('image/webp');
    });

    it('permite subirla a quien no tiene rol y reemplaza la anterior', function () {
        $user = User::factory()->create();
        $anterior = conFotoApi($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/user/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)])->assertOk();

        Storage::disk('local')->assertMissing($anterior);
    });

    it('devuelve 422 con lo que no es una imagen válida', function (Closure $archivo) {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/user/avatar', ['avatar' => $archivo()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    })->with([
        'texto' => [fn () => UploadedFile::fake()->createWithContent('a.jpg', 'no soy una imagen')],
        'SVG' => [fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')],
        'más de 2 MB' => [fn () => UploadedFile::fake()->image('a.jpg', 200, 200)->size(3000)],
        'pequeña' => [fn () => UploadedFile::fake()->image('a.jpg', 20, 20)],
    ]);

    it('devuelve 422 si falta el campo', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/user/avatar', [])->assertUnprocessable()->assertJsonValidationErrors('avatar');
    });

    it('limita los intentos por minuto', function () {
        Sanctum::actingAs(User::factory()->create());

        foreach (range(1, config('rate_limits.avatar')) as $intento) {
            $this->postJson('/api/user/avatar', [])->assertUnprocessable();
        }

        $this->postJson('/api/user/avatar', [])->assertTooManyRequests();
    });
});

describe('quitar la foto', function () {
    it('quita la propia con 204 y borra el archivo', function () {
        $user = User::factory()->create();
        $path = conFotoApi($user);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/user/avatar')->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        expect($user->fresh()->avatar_path)->toBeNull();
    });

    it('permite a un administrador quitar la foto de otra persona', function () {
        $otra = User::factory()->create();
        $path = conFotoApi($otra);
        Sanctum::actingAs(userWithRole(RoleName::Admin));

        $this->deleteJson("/api/users/{$otra->id}/avatar")->assertNoContent();

        Storage::disk('local')->assertMissing($path);
    });

    it('devuelve 403 a quien no es administrador, a uno mismo por esa ruta y cuando no hay foto', function () {
        $otra = User::factory()->create();
        $path = conFotoApi($otra);
        $sinFoto = User::factory()->create();

        Sanctum::actingAs(userWithRole(RoleName::Cliente));
        $this->deleteJson("/api/users/{$otra->id}/avatar")->assertForbidden();

        $admin = userWithRole(RoleName::Admin);
        conFotoApi($admin);
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/users/{$admin->id}/avatar")->assertForbidden();
        $this->deleteJson("/api/users/{$sinFoto->id}/avatar")->assertForbidden();

        Storage::disk('local')->assertExists($path);
    });

    it('no existe una ruta para subir la foto de otra persona', function () {
        $otra = User::factory()->create();
        Sanctum::actingAs(userWithRole(RoleName::Admin));

        $this->postJson("/api/users/{$otra->id}/avatar", ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)])
            ->assertStatus(405);
    });
});

describe('en las respuestas', function () {
    it('incluye avatar_url (nulo sin foto) en /api/user y en el listado de usuarios', function () {
        $user = userWithRole(RoleName::Admin);
        $otra = User::factory()->create();
        $path = conFotoApi($otra);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertJsonPath('data.avatar_url', null);
        $this->getJson("/api/users/{$otra->id}")->assertJsonPath('data.avatar_url', route('avatars.show', basename($path)));
    });
});
