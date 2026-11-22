<?php

use App\Models\Classification;
use App\Models\Product;
use App\Models\ProductMedia;

describe('listado público', function () {
    it('se ve sin iniciar sesión y muestra solo productos activos con su disponibilidad', function () {
        Product::factory()->create(['name' => 'Lámpara visible', 'sale_price' => 149.9, 'purchase_price' => 55.55, 'stock' => 7]);
        Product::factory()->outOfStock()->create(['name' => 'Mesa agotada']);
        Product::factory()->inactive()->create(['name' => 'Silla inactiva']);
        Product::factory()->create(['name' => 'Sofá eliminado'])->delete();

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('Lámpara visible')
            ->assertSee('$149.90')
            ->assertSee('Disponible')
            ->assertSee('Mesa agotada')
            ->assertSee('Agotado')
            ->assertDontSee('Silla inactiva')
            ->assertDontSee('Sofá eliminado')
            ->assertDontSee('55.55');
    });

    it('busca por nombre y filtra por clasificación activa', function () {
        $iluminacion = Classification::factory()->create(['name' => 'Iluminación', 'is_active' => true]);
        $inactiva = Classification::factory()->create(['name' => 'Oculta', 'is_active' => false]);
        Product::factory()->create(['name' => 'Lámpara solar'])->classifications()->attach([$iluminacion->id, $inactiva->id]);
        Product::factory()->create(['name' => 'Mesa de roble']);

        $this->get(route('catalog.index', ['search' => 'solar']))
            ->assertSee('Lámpara solar')->assertDontSee('Mesa de roble');

        $this->get(route('catalog.index', ['classification' => $iluminacion->id]))
            ->assertSee('Lámpara solar')->assertDontSee('Mesa de roble')->assertSee('Quitar filtros');

        // Una clasificación inactiva no filtra ni se ofrece al público.
        $this->get(route('catalog.index', ['classification' => $inactiva->id]))
            ->assertSee('Lámpara solar')->assertSee('Mesa de roble');
    });

    it('pagina de 10 en 10', function () {
        Product::factory()->count(11)->sequence(fn ($sequence) => ['name' => sprintf('Producto %02d', $sequence->index + 1)])->create();

        $this->get(route('catalog.index'))->assertSee('Producto 10')->assertDontSee('Producto 11')->assertSee('page=2', false);
        $this->get(route('catalog.index', ['page' => 2]))->assertSee('Producto 11')->assertDontSee('Producto 01');
    });

    it('muestra cinco productos por fila: con 10 por defecto son dos filas completas y el paginador', function () {
        Product::factory()->count(22)->create();

        $response = $this->get(route('catalog.index'))->assertOk();
        $html = $response->getContent();

        expect($html)->toContain('row-cols-lg-5')
            ->and(substr_count($html, 'card h-100 mb-0'))->toBe(10)
            ->and($html)->toContain('Mostrando')
            ->and($html)->toContain('page=2');
    });

    it('pone el buscador a la derecha del título, en la misma línea', function () {
        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        $title = strpos($html, 'Catálogo de productos</h1>');
        $search = strpos($html, 'name="search"');
        $line = substr($html, strrpos(substr($html, 0, $title), 'justify-content-between'), $search - $title + 400);

        expect($title)->not->toBeFalse()->and($search)->toBeGreaterThan($title)
            ->and($line)->toContain('justify-content-end');
    });

    it('ofrece el tamaño de página 5, 10, 20 o 50 y por defecto usa 10', function () {
        Product::factory()->count(22)->create();

        $html = $this->get(route('catalog.index'))->assertOk()->assertSee('Mostrar')->getContent();

        foreach ([5, 10, 20, 50] as $size) {
            expect($html)->toContain("per_page={$size}");
        }

        expect($html)->toMatch('~per_page=10"\s+selected~');

        foreach ([5 => 5, 20 => 20, 50 => 22] as $size => $shown) {
            $count = substr_count($this->get(route('catalog.index', ['per_page' => $size]))->getContent(), 'card h-100 mb-0');
            expect($count)->toBe($shown);
        }
    });

    it('rechaza un tamaño que no sea 5, 10, 20 o 50', function () {
        $this->get(route('catalog.index', ['per_page' => 7]))->assertSessionHasErrors('per_page');
    });

    it('conserva el tamaño elegido al buscar, al quitar filtros y al cambiar de página', function () {
        Product::factory()->count(12)->sequence(fn ($sequence) => ['name' => sprintf('Lámpara %02d', $sequence->index + 1)])->create();

        $this->get(route('catalog.index', ['per_page' => 5, 'search' => 'Lámpara']))
            ->assertSee('<input type="hidden" name="per_page" value="5">', false)
            ->assertSee('per_page=5&amp;search=L%C3%A1mpara&amp;page=2', false)
            ->assertSee(route('catalog.index', ['per_page' => 5]), false);
    });

    it('conserva la búsqueda al cambiar de página', function () {
        Product::factory()->count(11)->sequence(fn ($sequence) => ['name' => sprintf('Lámpara %02d', $sequence->index + 1)])->create();

        $this->get(route('catalog.index', ['search' => 'Lámpara']))
            ->assertSee('search=L%C3%A1mpara&amp;page=2', false);
    });

    it('muestra un aviso cuando no hay resultados y escapa lo que se busca', function () {
        $this->get(route('catalog.index', ['search' => '<script>alert(1)</script>']))
            ->assertOk()
            ->assertSee('No se encontraron productos.')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });

    it('rechaza filtros inválidos', function () {
        $this->get(route('catalog.index', ['classification' => 'abc']))->assertSessionHasErrors('classification');
        $this->get(route('catalog.index', ['search' => str_repeat('a', 101)]))->assertSessionHasErrors('search');
    });

    it('limita las peticiones por minuto', function () {
        config(['rate_limits.catalog' => 3]);

        foreach (range(1, 3) as $attempt) {
            $this->get(route('catalog.index'))->assertOk();
        }

        $this->get(route('catalog.index'))->assertStatus(429);
    });
});

describe('ficha pública', function () {
    it('muestra el producto activo sin datos internos ni controles de gestión', function () {
        $classification = Classification::factory()->create(['name' => 'Iluminación', 'is_active' => true]);
        $product = Product::factory()->create([
            'name' => 'Lámpara solar', 'description' => "Ilumina el jardín.\nCarga con el sol.", 'sku' => 'LMP-001',
            'sale_price' => 149.9, 'purchase_price' => 55.55, 'stock' => 7, 'min_stock' => 2,
        ]);
        $product->classifications()->attach($classification);
        $media = ProductMedia::factory()->for($product)->create(['original_name' => 'foto.jpg']);

        $this->get(route('catalog.show', $product))
            ->assertOk()
            ->assertSee('Lámpara solar')
            ->assertSee('$149.90')
            ->assertSee('Ilumina el jardín.')
            ->assertSee('Iluminación')
            ->assertSee('LMP-001')
            ->assertSee('Disponible')
            ->assertSee($media->thumbnailUrl(), false)
            ->assertSee(route('catalog.index', ['classification' => $classification->id]), false)
            ->assertDontSee('55.55')
            ->assertDontSee('Agregar fotos o videos')
            ->assertDontSee('class="js-delete-media"', false)
            ->assertDontSee('Usar como portada')
            ->assertDontSee('de 9');
    });

    it('responde 404 si el producto está inactivo, eliminado o no existe', function () {
        $inactive = Product::factory()->inactive()->create();
        $deleted = Product::factory()->create();
        $deleted->delete();

        $this->get(route('catalog.show', $inactive))->assertNotFound();
        $this->get(route('catalog.show', $deleted))->assertNotFound();
        $this->get('/invitado/catalogo/no-existe')->assertNotFound();
    });

    it('oculta las clasificaciones inactivas y la galería cuando no hay archivos', function () {
        $product = Product::factory()->create();
        $product->classifications()->attach(Classification::factory()->create(['name' => 'Escondida', 'is_active' => false]));

        $this->get(route('catalog.show', $product))
            ->assertOk()
            ->assertDontSee('Escondida')
            ->assertDontSee('Fotos y videos');
    });

    it('escapa el contenido del producto', function () {
        $product = Product::factory()->create(['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>']);

        $html = $this->get(route('catalog.show', $product))->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<img src=x onerror');
    });
});
