<?php

use App\Models\User;
use App\RoleName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

/**
 * Guarda una foto de prueba para la persona y devuelve su ruta en el disco.
 */
function conFoto(User $user): string
{
    $path = 'avatars/'.str_repeat('a', 40).'.webp';
    Storage::disk('local')->put($path, 'contenido-de-prueba');
    $user->forceFill(['avatar_path' => $path])->save();

    return $path;
}

describe('subir la foto propia', function () {
    it('envía a los invitados al login', function () {
        $this->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('foto.jpg', 300, 300)])
            ->assertRedirect(route('login'));
    });

    it('guarda una foto cuadrada de 256 px en WebP con un nombre generado por el servidor', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('../../mi foto.png', 600, 400)])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success', 'Foto de perfil actualizada.');

        $path = $user->fresh()->avatar_path;
        expect($path)->toMatch('#^avatars/[A-Za-z0-9]{40}\.webp$#');
        Storage::disk('local')->assertExists($path);

        $info = getimagesizefromstring(Storage::disk('local')->get($path));
        expect([$info[0], $info[1]])->toBe([256, 256])
            ->and($info['mime'])->toBe('image/webp');
    });

    it('permite cambiar la foto a cualquier persona con sesión, aunque no tenga rol', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)])
            ->assertSessionHasNoErrors();

        expect($user->fresh()->hasAvatar())->toBeTrue();
    });

    it('borra la foto anterior al subir una nueva', function () {
        $user = User::factory()->create();
        $anterior = conFoto($user);

        $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('b.jpg', 200, 200)]);

        Storage::disk('local')->assertMissing($anterior);
        expect($user->fresh()->avatar_path)->not->toBe($anterior);
        Storage::disk('local')->assertExists($user->fresh()->avatar_path);
    });

    it('cambia solo la foto de quien la sube', function () {
        $otra = User::factory()->create();
        $rutaOtra = conFoto($otra);

        $this->actingAs(User::factory()->create())
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('c.jpg', 200, 200)]);

        expect($otra->fresh()->avatar_path)->toBe($rutaOtra);
    });

    it('rechaza lo que no es una imagen válida y no toca la foto actual', function (string $caso, Closure $archivo) {
        $user = User::factory()->create();
        $actual = conFoto($user);

        $this->actingAs($user)
            ->post(route('profile.avatar.update'), ['avatar' => $archivo()])
            ->assertSessionHasErrors('avatar');

        expect($user->fresh()->avatar_path)->toBe($actual);
        expect(Storage::disk('local')->allFiles('avatars'))->toHaveCount(1);
    })->with([
        'texto con extensión .jpg' => ['texto', fn () => UploadedFile::fake()->createWithContent('a.jpg', 'no soy una imagen')],
        'SVG' => ['svg', fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
        'GIF' => ['gif', fn () => UploadedFile::fake()->image('a.gif', 200, 200)],
        'PDF' => ['pdf', fn () => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf')],
        'más de 2 MB' => ['peso', fn () => UploadedFile::fake()->image('a.jpg', 200, 200)->size(3000)],
        'demasiado pequeña' => ['pequeña', fn () => UploadedFile::fake()->image('a.jpg', 32, 32)],
        'demasiado grande' => ['grande', fn () => UploadedFile::fake()->image('a.png', 3500, 100)],
    ]);

    it('exige el campo', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('profile.avatar.update'), [])
            ->assertSessionHasErrors('avatar');
    });

    it('limita los intentos por minuto', function () {
        $this->actingAs(User::factory()->create());

        foreach (range(1, config('rate_limits.avatar')) as $intento) {
            $this->post(route('profile.avatar.update'), [])->assertSessionHasErrors('avatar');
        }

        $this->post(route('profile.avatar.update'), [])->assertTooManyRequests();
    });
});

describe('quitar la foto', function () {
    it('quita la propia y borra el archivo', function () {
        $user = User::factory()->create();
        $path = conFoto($user);

        $this->actingAs($user)
            ->delete(route('profile.avatar.destroy'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        Storage::disk('local')->assertMissing($path);
        expect($user->fresh()->avatar_path)->toBeNull();
    });

    it('no falla si no tenía foto', function () {
        $this->actingAs(User::factory()->create())->delete(route('profile.avatar.destroy'))->assertRedirect(route('profile.edit'));
    });

    it('permite a un administrador quitar la foto de otra persona', function () {
        $otra = User::factory()->create();
        $path = conFoto($otra);

        $this->actingAs(userWithRole(RoleName::Admin))
            ->delete(route('users.avatar.destroy', $otra))
            ->assertRedirect()
            ->assertSessionHas('success');

        Storage::disk('local')->assertMissing($path);
        expect($otra->fresh()->avatar_path)->toBeNull();
    });

    it('niega a quien no es administrador quitar la foto de otra persona', function () {
        $otra = User::factory()->create();
        $path = conFoto($otra);

        $this->actingAs(userWithRole(RoleName::Cliente))->delete(route('users.avatar.destroy', $otra))->assertForbidden();
        $this->actingAs(User::factory()->create())->delete(route('users.avatar.destroy', $otra))->assertForbidden();

        Storage::disk('local')->assertExists($path);
    });

    it('niega al administrador usar esa ruta para la suya o para quien no tiene foto', function () {
        $admin = userWithRole(RoleName::Admin);
        conFoto($admin);
        $sinFoto = User::factory()->create();

        $this->actingAs($admin)->delete(route('users.avatar.destroy', $admin))->assertForbidden();
        $this->actingAs($admin)->delete(route('users.avatar.destroy', $sinFoto))->assertForbidden();
    });

    it('no existe una ruta para que un administrador suba la foto de otra persona', function () {
        $otra = User::factory()->create();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post("/users/{$otra->id}/avatar", ['avatar' => UploadedFile::fake()->image('d.jpg', 200, 200)])
            ->assertStatus(405);

        expect($otra->fresh()->hasAvatar())->toBeFalse();
    });
});

describe('entrega de la foto', function () {
    it('la sirve a cualquiera con cabeceras de caché largas, sin cookies ni no-store', function () {
        $user = User::factory()->create();
        $path = conFoto($user);

        $respuesta = $this->get(route('avatars.show', basename($path)))->assertOk();

        $cache = $respuesta->headers->get('Cache-Control');
        expect($respuesta->headers->get('Content-Type'))->toBe('image/webp')
            ->and($cache)->toContain('public')->toContain('max-age=31536000')->toContain('immutable')
            ->and($cache)->not->toContain('no-store')
            ->and($respuesta->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($respuesta->headers->getCookies())->toBeEmpty();
    });

    it('responde 404 si el archivo no existe', function () {
        $this->get(route('avatars.show', str_repeat('b', 40).'.webp'))->assertNotFound();
    });

    it('solo acepta nombres de 40 caracteres alfanuméricos y .webp', function (string $nombre) {
        conFoto(User::factory()->create());

        $this->get("/avatars/{$nombre}")->assertNotFound();
    })->with([
        'corto' => ['abc.webp'],
        'otra extensión' => [str_repeat('a', 40).'.php'],
        'sin extensión' => [str_repeat('a', 40)],
        'con ruta' => ['..%2F'.str_repeat('a', 35).'.webp'],
        'con punto' => [str_repeat('a', 39).'..webp'],
    ]);
});

describe('en el perfil', function () {
    it('muestra el editor con el lápiz y sin la opción de quitar cuando no hay foto', function () {
        $this->actingAs(User::factory()->create(['name' => 'Ana Pérez']))
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('data-avatar-editor', false)
            ->assertSee('bi-pencil-fill', false)
            ->assertSee('data-bs-target="#avatarModal"', false)
            ->assertSee('Cambiar foto de perfil')
            ->assertSee('Elegir una imagen')
            ->assertSee('Tomar una foto')
            ->assertSee('AP')
            ->assertDontSee('Quitar mi foto');
    });

    it('muestra la foto y la opción de quitarla cuando la hay', function () {
        $user = User::factory()->create();
        $path = conFoto($user);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertSee(route('avatars.show', basename($path)), false)
            ->assertSee('Quitar mi foto');
    });
});
