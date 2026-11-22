<?php

use App\Models\Classification;
use App\Models\Product;
use App\Models\User;
use App\RoleName;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

describe('acceso', function () {
    it('responde 401 sin autenticación', function () {
        $this->getJson('/api/products')->assertUnauthorized();
    });

    it('responde 403 a un usuario sin rol', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products')->assertForbidden();
    });

    it('permite al cliente consultar', function () {
        $product = Product::factory()->create();
        Sanctum::actingAs(userWithRole(RoleName::Cliente));

        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.slug', $product->slug);
        $this->getJson("/api/products/{$product->slug}")->assertOk()->assertJsonPath('data.sku', $product->sku);
    });

    it('responde 403 en JSON al cliente en las acciones de escritura', function (string $method, string $uri) {
        $product = Product::factory()->create();
        Sanctum::actingAs(userWithRole(RoleName::Cliente));

        $this->json($method, str_replace('{slug}', $product->slug, $uri))
            ->assertForbidden()
            ->assertJsonStructure(['message']);

        $this->assertNotSoftDeleted($product);
    })->with([
        'creación' => ['POST', '/api/products'],
        'reemplazo' => ['PUT', '/api/products/{slug}'],
        'actualización parcial' => ['PATCH', '/api/products/{slug}'],
        'eliminación' => ['DELETE', '/api/products/{slug}'],
    ]);
});

describe('listado', function () {
    beforeEach(function () {
        // Con el rol que edita productos: el cliente ve solo los activos por defecto (ver "estado por defecto").
        Sanctum::actingAs(userWithRole(RoleName::Admin));

        $this->electronica = Classification::factory()->create();
        $this->hogar = Classification::factory()->create();

        Product::factory()->create([
            'name' => 'Lámpara', 'sku' => 'AAA-1', 'stock' => 50, 'min_stock' => 5, 'sale_price' => 100,
        ])->classifications()->attach($this->electronica);
        Product::factory()->create([
            'name' => 'Sartén', 'sku' => 'BBB-2', 'stock' => 3, 'min_stock' => 5, 'sale_price' => 250, 'is_active' => false,
        ])->classifications()->attach($this->hogar);
        Product::factory()->create([
            'name' => 'Mesa', 'sku' => 'CCC-3', 'stock' => 0, 'min_stock' => 5, 'sale_price' => 900, 'barcode' => '4006381333931',
        ])->classifications()->attach([$this->electronica->id, $this->hogar->id]);
    });

    it('filtra por los criterios indicados', function (array $query, array $expected) {
        $names = collect($this->getJson('/api/products?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();

        expect($names)->toBe($expected);
    })->with([
        'por nombre' => [['search' => 'Lámp'], ['Lámpara']],
        'por SKU' => [['search' => 'BBB'], ['Sartén']],
        'por código de barras' => [['search' => '4006381333931'], ['Mesa']],
        'sin existencias' => [['stock_status' => 'out'], ['Mesa']],
        'stock bajo' => [['stock_status' => 'low'], ['Sartén']],
        'stock suficiente' => [['stock_status' => 'ok'], ['Lámpara']],
        'inactivos' => [['is_active' => 0], ['Sartén']],
        'activos' => [['is_active' => 1], ['Lámpara', 'Mesa']],
        'precio mínimo' => [['price_min' => 250], ['Mesa', 'Sartén']],
        'precio máximo' => [['price_max' => 250], ['Lámpara', 'Sartén']],
        'rango de precio' => [['price_min' => 100, 'price_max' => 250], ['Lámpara', 'Sartén']],
        'combinados' => [['is_active' => 1, 'price_max' => 500], ['Lámpara']],
    ]);

    it('filtra por clasificación y basta con tener alguna de las indicadas', function () {
        $names = fn (array $ids) => collect(
            $this->getJson('/api/products?'.http_build_query(['classifications' => $ids]))->assertOk()->json('data')
        )->pluck('name')->sort()->values()->all();

        expect($names([$this->electronica->id]))->toBe(['Lámpara', 'Mesa'])
            ->and($names([$this->hogar->id]))->toBe(['Mesa', 'Sartén'])
            ->and($names([$this->electronica->id, $this->hogar->id]))->toBe(['Lámpara', 'Mesa', 'Sartén']);
    });

    it('rechaza filtros inválidos', function (array $query, string $field) {
        $this->getJson('/api/products?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'existencias desconocidas' => [['stock_status' => 'nada'], 'stock_status'],
        'rango invertido' => [['price_min' => 10, 'price_max' => 5], 'price_max'],
        'demasiados por página' => [['per_page' => 101], 'per_page'],
    ]);

    it('incluye las clasificaciones sin una consulta por producto', function () {
        // La primera petición carga los permisos del usuario; se calienta antes de medir.
        $this->getJson('/api/products')->assertOk();

        DB::enableQueryLog();
        $first = $this->getJson('/api/products')->assertOk()->assertJsonCount(3, 'data');
        $few = count(DB::getQueryLog());

        Product::factory()->count(5)->create();

        DB::flushQueryLog();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(8, 'data');

        expect(count(DB::getQueryLog()))->toBe($few)
            ->and($first->json('data.0.classifications'))->toBeArray();
    });
});

describe('escritura', function () {
    beforeEach(fn () => Sanctum::actingAs($this->admin = userWithRole(RoleName::Admin)));

    it('crea un producto con los datos generados', function () {
        $classification = Classification::factory()->create();

        $response = $this->postJson('/api/products', productPayload(['classification_ids' => [$classification->id]]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'lampara-solar')
            ->assertJsonPath('data.sale_price', 149.9)
            ->assertJsonPath('data.stock_status', 'ok')
            ->assertJsonPath('data.classifications.0.id', $classification->id);

        $product = Product::findOrFail($response->json('data.id'));

        expect($response->json('data.sku'))->toBe(sprintf('PRD-%06d', $product->id))
            ->and($product->created_by)->toBe($this->admin->id)
            ->and($product->barcode)->toHaveLength(13);
    });

    it('ignora el slug y el autor enviados', function () {
        $other = User::factory()->create();

        $this->postJson('/api/products', productPayload(['slug' => 'hackeado', 'created_by' => $other->id]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'lampara-solar');

        expect(Product::first()->created_by)->toBe($this->admin->id);
    });

    it('responde 422 con datos inválidos o repetidos', function () {
        Product::factory()->create(['sku' => 'LMP-001']);

        $this->postJson('/api/products', productPayload(['name' => '', 'sku' => 'LMP-001', 'barcode' => '123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sku', 'barcode']);
    });

    it('consulta por slug y responde 404 si fue eliminado', function () {
        $product = Product::factory()->create();

        $this->getJson("/api/products/{$product->slug}")->assertOk()->assertJsonPath('data.name', $product->name);

        $product->delete();

        $this->getJson("/api/products/{$product->slug}")->assertNotFound();
    });

    it('actualiza parcialmente sin tocar el resto de campos', function () {
        $product = Product::factory()->create(['name' => 'Lámpara', 'stock' => 10]);

        $this->patchJson("/api/products/{$product->slug}", ['stock' => 5])
            ->assertOk()
            ->assertJsonPath('data.stock', 5)
            ->assertJsonPath('data.name', 'Lámpara');

        expect($product->refresh()->updated_by)->toBe($this->admin->id);
    });

    it('exige un nombre de al menos 3 caracteres y una venta mayor que la compra al crear', function () {
        $this->postJson('/api/products', productPayload(['name' => 'ab', 'purchase_price' => '50.00', 'sale_price' => '50.00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sale_price']);
    });

    it('valida la venta contra la compra guardada cuando solo se cambia uno de los dos precios', function () {
        $product = Product::factory()->create(['purchase_price' => 100, 'sale_price' => 150]);

        $this->patchJson("/api/products/{$product->slug}", ['sale_price' => 90])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sale_price');

        $this->patchJson("/api/products/{$product->slug}", ['purchase_price' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purchase_price');

        $this->patchJson("/api/products/{$product->slug}", ['purchase_price' => 120])->assertOk();
        $this->patchJson("/api/products/{$product->slug}", ['sale_price' => 130, 'purchase_price' => 100])->assertOk();
        $this->patchJson("/api/products/{$product->slug}", ['name' => 'ab'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('rechaza actualizaciones parciales inválidas', function () {
        $product = Product::factory()->create();

        $this->patchJson("/api/products/{$product->slug}", ['sale_price' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sale_price');
    });

    it('elimina de forma lógica', function () {
        $product = Product::factory()->create();

        $this->deleteJson("/api/products/{$product->slug}")->assertNoContent();

        $this->assertSoftDeleted($product);
    });
});

describe('estado por defecto', function () {
    it('muestra al cliente solo los activos hasta que pida otro estado', function () {
        Product::factory()->create(['name' => 'Activo visible']);
        Product::factory()->inactive()->create(['name' => 'Inactivo oculto']);
        Sanctum::actingAs(userWithRole(RoleName::Cliente));

        $names = fn (string $query): array => collect($this->getJson("/api/products{$query}")->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();

        expect($names(''))->toBe(['Activo visible'])
            ->and($names('?is_active=0'))->toBe(['Inactivo oculto'])
            ->and($names('?is_active='))->toBe(['Activo visible', 'Inactivo oculto']);
    });

    it('muestra al administrador todos por defecto', function () {
        Product::factory()->create(['name' => 'Activo visible']);
        Product::factory()->inactive()->create(['name' => 'Inactivo también']);
        Sanctum::actingAs(userWithRole(RoleName::Admin));

        $this->getJson('/api/products')->assertOk()->assertJsonCount(2, 'data');
    });
});
