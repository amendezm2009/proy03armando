<?php

use App\Models\User;
use App\RoleName;
use Illuminate\Support\Facades\Storage;

describe('iniciales', function () {
    it('toma la inicial de las dos primeras palabras o las dos primeras letras de una sola', function (string $nombre, string $iniciales) {
        expect(User::factory()->make(['name' => $nombre])->initials())->toBe($iniciales);
    })->with([
        'dos palabras' => ['Ana Pérez', 'AP'],
        'tres palabras' => ['Armando Méndez Mata', 'AM'],
        'espacios de más y minúsculas' => ['  luis    gómez ', 'LG'],
        'una sola palabra' => ['Madonna', 'MA'],
        'una sola letra' => ['X', 'X'],
        'eñe y tildes' => ['ñandú árbol', 'ÑÁ'],
        'apóstrofo' => ["O'Hara Smith", 'OS'],
        'palabra con guion' => ['Ana-María López', 'AL'],
        'símbolos sueltos' => ['- Ana @ Pérez', 'AP'],
        'solo símbolos' => ['!!! ???', '?'],
        'vacío' => ['', '?'],
    ]);
});

describe('componente', function () {
    it('muestra las iniciales con un color que siempre es el mismo para el mismo nombre', function () {
        $user = User::factory()->make(['name' => 'Ana Pérez']);

        $primera = (string) $this->blade('<x-avatar :user="$user" :size="40" />', ['user' => $user]);
        $segunda = (string) $this->blade('<x-avatar :user="$user" :size="40" />', ['user' => $user]);

        expect($primera)->toContain('AP')->toContain('rounded-circle')->toContain('width: 40px')
            ->and(preg_match('/background-color: (#[0-9a-f]{6})/', $primera, $color))->toBe(1)
            ->and($segunda)->toBe($primera)
            ->and($color[1])->toBeIn(['#0b5ed7', '#6f42c1', '#a61e4d', '#146c43', '#b45309', '#0a7285', '#5c3d99', '#374151']);
    });

    it('oculta el avatar a los lectores de pantalla salvo que se indique lo contrario', function () {
        $user = User::factory()->make(['name' => 'Ana Pérez']);

        $this->blade('<x-avatar :user="$user" />', ['user' => $user])->assertSee('aria-hidden="true"', false);
        $this->blade('<x-avatar :user="$user" :decorative="false" />', ['user' => $user])
            ->assertSee('role="img"', false)
            ->assertSee('aria-label="Iniciales de Ana Pérez"', false);
    });

    it('muestra la foto y no las iniciales cuando la hay', function () {
        $user = User::factory()->make(['name' => 'Ana Pérez', 'avatar_path' => 'avatars/'.str_repeat('a', 40).'.webp']);

        $this->blade('<x-avatar :user="$user" :size="64" />', ['user' => $user])
            ->assertSee('<img', false)
            ->assertSee(route('avatars.show', str_repeat('a', 40).'.webp'), false)
            ->assertSee('width="64"', false)
            ->assertSee('alt=""', false)
            ->assertDontSee('AP');

        $this->blade('<x-avatar :user="$user" :decorative="false" />', ['user' => $user])
            ->assertSee('alt="Foto de perfil de Ana Pérez"', false);
    });

    it('escapa el nombre para que no se pueda inyectar HTML', function () {
        $user = User::factory()->make(['name' => '"><script>alert(1)</script>']);

        $this->blade('<x-avatar :user="$user" :decorative="false" />', ['user' => $user])
            ->assertDontSee('<script>', false);
    });
});

describe('en las páginas', function () {
    it('el menú muestra el avatar de quien tiene sesión y el icono genérico al visitante', function () {
        $this->get(route('home'))->assertSee('bi-person-circle', false);

        $this->actingAs(User::factory()->create(['name' => 'Ana Pérez']))
            ->get(route('home'))
            ->assertSee('AP')
            ->assertDontSee('bi-person-circle', false);
    });

    it('el listado de usuarios muestra el avatar de cada persona, con foto o con iniciales', function () {
        Storage::fake('local');
        User::factory()->create(['name' => 'Luis Gómez']);
        User::factory()->create(['name' => 'Eva Ruiz', 'avatar_path' => 'avatars/'.str_repeat('c', 40).'.webp']);

        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('users.index'))
            ->assertSee('LG')
            ->assertSee(route('avatars.show', str_repeat('c', 40).'.webp'), false);
    });
});
