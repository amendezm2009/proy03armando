<?php

// test('example', function () {
//     $response = $this->get('/');

//     $response->assertStatus(200);
// });

use App\Models\Classification;
use App\RoleName;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(fn () => $this->actingAs(userWithRole(RoleName::Admin)));

describe('listado', function () {
    it('muestra las clasificaciones existentes', function () {
        Classification::factory()->create(['name' => 'Electrónica']);

        $this->get(route('classifications.index'))
            ->assertOk()
            ->assertSee('Electrónica');
    });
});

describe('creación', function () {
    it('crea una clasificación y genera su slug', function () {
        $this->post(route('classifications.store'), Classification::factory()->raw(['name' => 'Ropa y calzado']))
            ->assertRedirect(route('classifications.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('classifications', ['name' => 'Ropa y calzado', 'slug' => 'ropa-y-calzado']);
    });

    it('guarda is_active como falso cuando el campo oculto envía 0', function () {
        $this->post(route('classifications.store'), Classification::factory()->raw(['is_active' => '0']));

        expect(Classification::first()->is_active)->toBeFalse();
    });

    it('rechaza un nombre duplicado', function () {
        Classification::factory()->create(['name' => 'Hogar']);

        $this->post(route('classifications.store'), Classification::factory()->raw(['name' => 'Hogar']))
            ->assertSessionHasErrors('name');

        expect(Classification::count())->toBe(1);
    });

    it('rechaza un nombre vacío y un color inválido', function () {
        $this->post(route('classifications.store'), ['color_badge' => 'rojo'])
            ->assertSessionHasErrors(['name', 'color_badge']);

        expect(Classification::count())->toBe(0);
    });
});

describe('actualización', function () {
    it('actualiza los datos y regenera el slug', function () {
        $classification = Classification::factory()->create(['name' => 'Hogar']);

        $this->put(route('classifications.update', $classification), Classification::factory()->raw(['name' => 'Jardín y hogar']))
            ->assertRedirect(route('classifications.index'));

        expect($classification->fresh())
            ->name->toBe('Jardín y hogar')
            ->slug->toBe('jardin-y-hogar');
    });

    it('permite guardar la clasificación con su propio nombre', function () {
        $classification = Classification::factory()->create(['name' => 'Hogar']);

        $this->put(route('classifications.update', $classification), Classification::factory()->raw(['name' => 'Hogar']))
            ->assertSessionHasNoErrors();
    });

    it('rechaza el nombre de otra clasificación', function () {
        Classification::factory()->create(['name' => 'Hogar']);
        $other = Classification::factory()->create(['name' => 'Deportes']);

        $this->put(route('classifications.update', $other), Classification::factory()->raw(['name' => 'Hogar']))
            ->assertSessionHasErrors('name');
    });
});

describe('eliminación', function () {
    it('elimina la clasificación', function () {
        $classification = Classification::factory()->create();

        $this->delete(route('classifications.destroy', $classification))
            ->assertRedirect(route('classifications.index'));

        $this->assertModelMissing($classification);
    });
});

describe('exportación', function () {
    it('descarga el archivo de Excel', function () {
        Excel::fake();
        Classification::factory()->count(2)->create();

        $this->get(route('classifications.export'))->assertOk();

        Excel::assertDownloaded('clasificaciones.xlsx');
    });
});

describe('importación', function () {
    $csv = fn (string $rows): UploadedFile => UploadedFile::fake()->createWithContent(
        'clasificaciones.csv',
        "name,description,color_badge,is_active\n".$rows
    );

    it('crea las clasificaciones del archivo y toma is_active vacío como activo', function () use ($csv) {
        $this->post(route('classifications.import'), [
            'file' => $csv("Libros,Libros y revistas,#6f42c1,0\nJuguetes,,#fd7e14,\n"),
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('classifications', ['name' => 'Libros', 'slug' => 'libros', 'is_active' => false]);
        $this->assertDatabaseHas('classifications', ['name' => 'Juguetes', 'is_active' => true]);
    });

    it('informa el número de fila de una fila inválida', function () use ($csv) {
        $this->post(route('classifications.import'), [
            'file' => $csv("Libros,,rojo,1\n"),
        ])->assertSessionHasErrors();

        expect(session('errors')->first())->toContain('Fila 2');
        expect(Classification::count())->toBe(0);
    });

    it('rechaza un nombre que ya existe', function () use ($csv) {
        Classification::factory()->create(['name' => 'Libros']);

        $this->post(route('classifications.import'), [
            'file' => $csv("Libros,,#6f42c1,1\n"),
        ])->assertSessionHasErrors();

        expect(Classification::count())->toBe(1);
    });

    it('rechaza un nombre repetido dentro del mismo archivo', function () use ($csv) {
        $this->post(route('classifications.import'), [
            'file' => $csv("Libros,,#6f42c1,1\nLibros,,#fd7e14,1\n"),
        ])->assertSessionHasErrors();
    });

    it('rechaza un archivo con una extensión no permitida', function () {
        $this->post(route('classifications.import'), [
            'file' => UploadedFile::fake()->create('datos.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    });
});
