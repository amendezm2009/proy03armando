<?php

// test('example', function () {
//     $response = $this->get('/');

//     $response->assertStatus(200);
// });

use App\Models\Classification;

describe('listado', function () {
    it('devuelve 200 con una lista paginada', function () {
        Classification::factory()->count(3)->create();

        $this->getJson('/api/classifications')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'description', 'color_badge', 'is_active', 'created_at', 'updated_at']],
                'links',
                'meta',
            ]);
    });
});

describe('creación', function () {
    it('devuelve 201 con el recurso creado', function () {
        $this->postJson('/api/classifications', Classification::factory()->raw(['name' => 'Papelería']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Papelería')
            ->assertJsonPath('data.slug', 'papeleria');

        $this->assertDatabaseHas('classifications', ['name' => 'Papelería']);
    });

    it('devuelve 422 cuando faltan campos obligatorios', function () {
        $this->postJson('/api/classifications', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'color_badge']);
    });

    it('devuelve 422 cuando el nombre ya existe', function () {
        Classification::factory()->create(['name' => 'Hogar']);

        $this->postJson('/api/classifications', Classification::factory()->raw(['name' => 'Hogar']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });
});

describe('consulta individual', function () {
    it('devuelve 200 con el recurso', function () {
        $classification = Classification::factory()->create();

        $this->getJson("/api/classifications/{$classification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $classification->id);
    });

    it('devuelve 404 cuando la clasificación no existe', function () {
        $this->getJson('/api/classifications/999')->assertNotFound();
    });
});

describe('actualización', function () {
    it('reemplaza los datos con PUT', function () {
        $classification = Classification::factory()->create(['name' => 'Hogar']);

        $this->putJson("/api/classifications/{$classification->id}", Classification::factory()->raw(['name' => 'Jardín']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Jardín')
            ->assertJsonPath('data.slug', 'jardin');
    });

    it('devuelve 422 con PUT cuando falta un campo obligatorio', function () {
        $classification = Classification::factory()->create();

        $this->putJson("/api/classifications/{$classification->id}", ['description' => 'solo descripción'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'color_badge']);
    });

    it('cambia solo los campos enviados con PATCH', function () {
        $classification = Classification::factory()->create(['name' => 'Hogar', 'color_badge' => '#198754']);

        $this->patchJson("/api/classifications/{$classification->id}", ['is_active' => false])
            ->assertOk();

        expect($classification->fresh())
            ->name->toBe('Hogar')
            ->color_badge->toBe('#198754')
            ->is_active->toBeFalse();
    });

    it('devuelve 422 con PATCH cuando un campo enviado es inválido', function () {
        $classification = Classification::factory()->create();

        $this->patchJson("/api/classifications/{$classification->id}", ['color_badge' => 'rojo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('color_badge');
    });
});

describe('eliminación', function () {
    it('devuelve 204 y elimina la clasificación', function () {
        $classification = Classification::factory()->create();

        $this->deleteJson("/api/classifications/{$classification->id}")->assertNoContent();

        $this->assertModelMissing($classification);
    });
});
