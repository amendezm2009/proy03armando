<?php

use App\Models\Classification;
use Illuminate\Database\Eloquent\Factories\Sequence;

describe('filtros', function () {
    it('filtra por texto', function () {
        Classification::factory()->create(['name' => 'Electrónica', 'slug' => 'electronica']);
        Classification::factory()->create(['name' => 'Hogar', 'slug' => 'hogar']);

        $this->getJson('/api/classifications?search=Hog')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Hogar');
    });

    it('filtra por estado inactivo', function () {
        Classification::factory()->create(['name' => 'Hogar', 'slug' => 'hogar', 'is_active' => true]);
        Classification::factory()->create(['name' => 'Deportes', 'slug' => 'deportes', 'is_active' => false]);

        $this->getJson('/api/classifications?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Deportes');
    });

    it('devuelve 422 con un estado inválido', function () {
        $this->getJson('/api/classifications?is_active=quizas')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    });
});

describe('paginación', function () {
    it('usa 15 registros por página por defecto', function () {
        Classification::factory()->count(20)->create();

        $this->getJson('/api/classifications')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 20);
    });

    it('respeta per_page y conserva los filtros en el enlace siguiente', function () {
        Classification::factory()->count(12)
            ->state(new Sequence(fn (Sequence $sequence) => ['name' => "Libro {$sequence->index}"]))
            ->create();

        $response = $this->getJson('/api/classifications?search=Libro&per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12);

        expect($response->json('links.next'))->toContain('search=Libro');
    });

    it('devuelve 422 cuando per_page supera el máximo', function () {
        $this->getJson('/api/classifications?per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    });
});
