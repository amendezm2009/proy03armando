<?php

use App\Models\Classification;
use App\RoleName;
use Illuminate\Database\Eloquent\Factories\Sequence;

beforeEach(fn () => $this->actingAs(userWithRole(RoleName::Admin)));

describe('paginación', function () {
    it('muestra 10 clasificaciones por página por defecto', function () {
        Classification::factory()->count(12)->create();

        $this->get(route('classifications.index'))
            ->assertOk()
            ->assertViewHas('classifications', fn ($page) => $page->count() === 10 && $page->total() === 12);
    });

    it('respeta el parámetro per_page', function () {
        Classification::factory()->count(12)->create();

        $this->get(route('classifications.index', ['per_page' => 5]))
            ->assertViewHas('classifications', fn ($page) => $page->count() === 5 && $page->lastPage() === 3);
    });

    it('conserva los filtros en los enlaces de paginación', function () {
        Classification::factory()->count(12)
            ->state(new Sequence(fn (Sequence $sequence) => ['name' => "Libro {$sequence->index}"]))
            ->create();

        $this->get(route('classifications.index', ['search' => 'Libro', 'per_page' => 5]))
            ->assertSee('search=Libro', false);
    });

    it('rechaza un per_page fuera del rango permitido', function () {
        $this->get(route('classifications.index', ['per_page' => 500]))
            ->assertSessionHasErrors('per_page');
    });
});

describe('filtros', function () {
    it('filtra por texto en el nombre', function () {
        Classification::factory()->create(['name' => 'Electrónica', 'slug' => 'electronica']);
        Classification::factory()->create(['name' => 'Hogar', 'slug' => 'hogar']);

        $this->get(route('classifications.index', ['search' => 'Hog']))
            ->assertSee('Hogar')
            ->assertDontSee('Electrónica');
    });

    it('filtra por texto en el slug', function () {
        Classification::factory()->create(['name' => 'Ropa', 'slug' => 'prendas-de-vestir']);
        Classification::factory()->create(['name' => 'Hogar', 'slug' => 'hogar']);

        $this->get(route('classifications.index', ['search' => 'prendas']))
            ->assertSee('Ropa')
            ->assertDontSee('Hogar');
    });

    it('muestra solo las clasificaciones activas', function () {
        Classification::factory()->create(['name' => 'Hogar', 'slug' => 'hogar', 'is_active' => true]);
        Classification::factory()->create(['name' => 'Deportes', 'slug' => 'deportes', 'is_active' => false]);

        $this->get(route('classifications.index', ['is_active' => 1]))
            ->assertSee('Hogar')
            ->assertDontSee('Deportes');
    });

    it('muestra solo las clasificaciones inactivas', function () {
        Classification::factory()->create(['name' => 'Hogar', 'slug' => 'hogar', 'is_active' => true]);
        Classification::factory()->create(['name' => 'Deportes', 'slug' => 'deportes', 'is_active' => false]);

        $this->get(route('classifications.index', ['is_active' => 0]))
            ->assertSee('Deportes')
            ->assertDontSee('Hogar');
    });

    it('combina búsqueda y estado', function () {
        Classification::factory()->create(['name' => 'Libros', 'slug' => 'libros', 'is_active' => true]);
        Classification::factory()->create(['name' => 'Libretas', 'slug' => 'libretas', 'is_active' => false]);

        $this->get(route('classifications.index', ['search' => 'Libr', 'is_active' => 0]))
            ->assertSee('Libretas')
            ->assertDontSee('Libros');
    });

    it('informa cuando no hay resultados', function () {
        $this->get(route('classifications.index', ['search' => 'inexistente']))
            ->assertOk()
            ->assertSee('No se encontraron clasificaciones');
    });

    it('rechaza un estado inválido', function () {
        $this->get(route('classifications.index', ['is_active' => 'quizas']))
            ->assertSessionHasErrors('is_active');
    });
});
