<?php

use App\Models\Classification;
use App\Models\User;
use App\RoleName;
use Laravel\Sanctum\Sanctum;

describe('cliente', function () {
    beforeEach(fn () => Sanctum::actingAs(userWithRole(RoleName::Cliente)));

    it('puede listar y consultar clasificaciones', function () {
        $classification = Classification::factory()->create();

        $this->getJson('/api/classifications')->assertOk();
        $this->getJson("/api/classifications/{$classification->id}")->assertOk();
    });

    it('recibe 403 en JSON en las acciones de escritura', function (string $method, string $uri) {
        $classification = Classification::factory()->create();

        $this->json($method, str_replace('{id}', (string) $classification->id, $uri))
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    })->with([
        'creación' => ['POST', '/api/classifications'],
        'reemplazo' => ['PUT', '/api/classifications/{id}'],
        'actualización parcial' => ['PATCH', '/api/classifications/{id}'],
        'eliminación' => ['DELETE', '/api/classifications/{id}'],
    ]);

    it('no elimina la clasificación', function () {
        $classification = Classification::factory()->create();

        $this->deleteJson("/api/classifications/{$classification->id}")->assertForbidden();

        $this->assertModelExists($classification);
    });
});

describe('sin rol', function () {
    beforeEach(fn () => Sanctum::actingAs(User::factory()->create()));

    it('recibe 403 al listar y al consultar', function () {
        $classification = Classification::factory()->create();

        $this->getJson('/api/classifications')->assertForbidden();
        $this->getJson("/api/classifications/{$classification->id}")->assertForbidden();
    });
});

it('incluye los roles del usuario en /api/user', function () {
    Sanctum::actingAs(userWithRole(RoleName::Admin));

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.roles', [RoleName::Admin->value]);
});
