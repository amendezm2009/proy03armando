<?php

use App\Actions\GenerateBarcode;
use App\Actions\SaveProduct;
use App\Models\Classification;
use App\Models\Product;
use App\Models\User;
use App\RoleName;
use Database\Seeders\ClassificationSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Support\Facades\DB;

describe('acceso', function () {
    it('redirige a las personas sin sesión al login', function () {
        $this->get(route('products.index'))->assertRedirect(route('login'));
    });

    it('niega el listado a un usuario sin rol', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('products.index'))
            ->assertForbidden();
    });

    it('no muestra el enlace de productos a un usuario sin rol', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee(route('products.index'));
    });

    it('permite al cliente consultar sin acciones de escritura ni precio de compra', function () {
        $product = Product::factory()->create(['purchase_price' => 123.45]);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertDontSee(route('products.create'))
            ->assertDontSee(route('products.edit', $product));

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee($product->name)
            ->assertDontSee('Precio de compra')
            ->assertDontSee('123.45');
    });

    it('rechaza al cliente en las acciones de escritura', function (string $method, string $uri) {
        $product = Product::factory()->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->call($method, str_replace('{slug}', $product->slug, $uri))->assertForbidden();

        $this->assertNotSoftDeleted($product);
    })->with([
        'formulario de alta' => ['GET', '/products/create'],
        'alta' => ['POST', '/products'],
        'formulario de edición' => ['GET', '/products/{slug}/edit'],
        'edición' => ['PUT', '/products/{slug}'],
        'eliminación' => ['DELETE', '/products/{slug}'],
    ]);

    it('muestra las acciones de escritura al administrador', function () {
        $product = Product::factory()->create();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('products.create'))
            ->assertSee(route('products.edit', $product));
    });
});

describe('listado', function () {
    beforeEach(fn () => $this->actingAs(userWithRole(RoleName::Admin)));

    it('muestra clasificaciones, precio formateado y avisos de existencias', function () {
        $classification = Classification::factory()->create(['name' => 'Iluminación']);
        $product = Product::factory()->lowStock()->create(['sale_price' => 1234.5]);
        $product->classifications()->attach($classification);
        Product::factory()->outOfStock()->create();

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Iluminación')
            ->assertSee('1,234.50')
            ->assertSee('Stock bajo')
            ->assertSee('Sin stock');
    });

    it('filtra por texto y por existencias', function () {
        Product::factory()->create(['name' => 'Zafiro azul']);
        Product::factory()->outOfStock()->create(['name' => 'Rubí rojo']);

        $this->get(route('products.index', ['search' => 'Zafiro']))
            ->assertSee('Zafiro azul')
            ->assertDontSee('Rubí rojo');

        $this->get(route('products.index', ['stock_status' => 'out']))
            ->assertSee('Rubí rojo')
            ->assertDontSee('Zafiro azul');
    });

    it('no hace más consultas al mostrar más productos', function () {
        $classification = Classification::factory()->create();
        $attach = fn (Product $product) => $product->classifications()->attach($classification);
        Product::factory()->count(2)->create()->each($attach);

        // La primera petición carga los permisos del usuario; se calienta antes de medir.
        $this->get(route('products.index'))->assertOk();

        DB::enableQueryLog();
        $this->get(route('products.index'))->assertOk();
        $few = count(DB::getQueryLog());

        Product::factory()->count(6)->create()->each($attach);

        DB::flushQueryLog();
        $this->get(route('products.index'))->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });

    it('rechaza filtros inválidos', function () {
        $this->get(route('products.index', ['price_min' => 10, 'price_max' => 5]))
            ->assertSessionHasErrors('price_max');
    });
});

describe('alta', function () {
    it('genera SKU, código de barras, slug y autoría cuando no se indican', function () {
        $admin = userWithRole(RoleName::Admin);
        $classification = Classification::factory()->create();

        $response = $this->actingAs($admin)
            ->post(route('products.store'), productPayload(['classification_ids' => [$classification->id]]));

        $product = Product::firstWhere('name', 'Lámpara solar');
        $response->assertRedirect(route('products.show', $product));

        expect($product->slug)->toBe('lampara-solar')
            ->and($product->sku)->toBe(sprintf('PRD-%06d', $product->id))
            ->and($product->barcode)->toBe(GenerateBarcode::forId($product->id))
            ->and($product->created_by)->toBe($admin->id)
            ->and($product->updated_by)->toBe($admin->id)
            ->and($product->classifications->modelKeys())->toBe([$classification->id])
            ->and($product->is_active)->toBeTrue();
    });

    it('respeta el SKU y el código de barras indicados', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.store'), productPayload(['sku' => 'LMP-001', 'barcode' => '4006381333931']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['sku' => 'LMP-001', 'barcode' => '4006381333931']);
    });

    it('ignora el slug y el autor que lleguen en la petición', function () {
        $admin = userWithRole(RoleName::Admin);
        $other = User::factory()->create();

        $this->actingAs($admin)->post(route('products.store'), productPayload([
            'slug' => 'hackeado',
            'created_by' => $other->id,
        ]));

        $product = Product::firstWhere('name', 'Lámpara solar');

        expect($product->slug)->toBe('lampara-solar')->and($product->created_by)->toBe($admin->id);
    });

    it('añade un sufijo al slug cuando el nombre ya existe, incluso en un producto eliminado', function () {
        $admin = userWithRole(RoleName::Admin);
        $save = app(SaveProduct::class);

        $first = $save->handle(productPayload(), $admin);
        $first->delete();
        $second = $save->handle(productPayload(), $admin);
        $third = $save->handle(productPayload(), $admin);

        expect($first->slug)->toBe('lampara-solar')
            ->and($second->slug)->toBe('lampara-solar-2')
            ->and($third->slug)->toBe('lampara-solar-3');
    });

    it('rechaza datos inválidos', function (array $override, string $field) {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.store'), productPayload($override))
            ->assertSessionHasErrors($field);

        expect(Product::count())->toBe(0);
    })->with([
        'sin nombre' => [['name' => ''], 'name'],
        'nombre demasiado largo' => [['name' => str_repeat('a', 151)], 'name'],
        'nombre de dos letras' => [['name' => 'ab'], 'name'],
        'precio de venta igual al de compra' => [['purchase_price' => '100.00', 'sale_price' => '100.00'], 'sale_price'],
        'precio de venta menor al de compra' => [['purchase_price' => '100.00', 'sale_price' => '50.00'], 'sale_price'],
        'precio de venta negativo' => [['sale_price' => -1], 'sale_price'],
        'precio con tres decimales' => [['sale_price' => '10.123'], 'sale_price'],
        'precio no numérico' => [['purchase_price' => 'abc'], 'purchase_price'],
        'stock negativo' => [['stock' => -1], 'stock'],
        'stock decimal' => [['stock' => '1.5'], 'stock'],
        'SKU con caracteres inválidos' => [['sku' => 'AB CD<'], 'sku'],
        'EAN-13 con control incorrecto' => [['barcode' => '4006381333932'], 'barcode'],
        'EAN-13 demasiado corto' => [['barcode' => '123'], 'barcode'],
        'clasificación inexistente' => [['classification_ids' => [999]], 'classification_ids.0'],
        'clasificación repetida' => [['classification_ids' => [1, 1]], 'classification_ids.0'],
    ]);

    it('acepta un nombre de tres letras y un precio de venta apenas mayor al de compra', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.store'), productPayload(['name' => 'Pan', 'purchase_price' => '10.00', 'sale_price' => '10.01']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['name' => 'Pan']);
    });

    it('muestra el mensaje en español cuando el precio de venta no supera al de compra', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->from(route('products.create'))
            ->post(route('products.store'), productPayload(['purchase_price' => '100.00', 'sale_price' => '90.00']))
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors(['sale_price' => 'El precio de venta debe ser mayor que el precio de compra.']);
    });

    it('rechaza un SKU o un código de barras repetidos, también de un producto eliminado', function (string $field, string $value) {
        Product::factory()->create(['sku' => 'LMP-001', 'barcode' => '4006381333931'])->delete();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.store'), productPayload([$field => $value]))
            ->assertSessionHasErrors($field);
    })->with([
        'sku' => ['sku', 'LMP-001'],
        'barcode' => ['barcode', '4006381333931'],
    ]);
});

describe('edición', function () {
    it('actualiza sin cambiar el slug y conserva el SKU y el código si se dejan vacíos', function () {
        $creator = User::factory()->create();
        $admin = userWithRole(RoleName::Admin);
        $product = app(SaveProduct::class)->handle(productPayload(), $creator);
        [$sku, $barcode] = [$product->sku, $product->barcode];

        $this->actingAs($admin)
            ->put(route('products.update', $product), productPayload([
                'name' => 'Nombre nuevo', 'sku' => '', 'barcode' => '', 'sale_price' => '200.00',
            ]))
            ->assertRedirect(route('products.show', $product));

        $product->refresh();

        expect($product->name)->toBe('Nombre nuevo')
            ->and($product->slug)->toBe('lampara-solar')
            ->and($product->sku)->toBe($sku)
            ->and($product->barcode)->toBe($barcode)
            ->and($product->sale_price)->toBe('200.00')
            ->and($product->created_by)->toBe($creator->id)
            ->and($product->updated_by)->toBe($admin->id);
    });

    it('permite conservar su propio SKU y código al guardar', function () {
        $admin = userWithRole(RoleName::Admin);
        $product = Product::factory()->create(['sku' => 'LMP-001', 'barcode' => '4006381333931']);

        $this->actingAs($admin)
            ->put(route('products.update', $product), productPayload(['sku' => 'LMP-001', 'barcode' => '4006381333931']))
            ->assertSessionHasNoErrors();
    });

    it('reemplaza las clasificaciones y permite quitarlas todas', function () {
        $admin = userWithRole(RoleName::Admin);
        [$a, $b] = Classification::factory()->count(2)->create();
        $product = app(SaveProduct::class)->handle(productPayload(['classification_ids' => [$a->id]]), $admin);

        $this->actingAs($admin)
            ->put(route('products.update', $product), productPayload(['classification_ids' => [$b->id]]));
        expect($product->classifications()->pluck('classifications.id')->all())->toBe([$b->id]);

        $this->put(route('products.update', $product), productPayload(['classification_ids' => ['']]));
        expect($product->classifications()->count())->toBe(0);
    });

    it('conserva las clasificaciones si la petición no las menciona', function () {
        $admin = userWithRole(RoleName::Admin);
        $classification = Classification::factory()->create();
        $product = app(SaveProduct::class)->handle(productPayload(['classification_ids' => [$classification->id]]), $admin);

        $this->actingAs($admin)->put(route('products.update', $product), productPayload(['name' => 'Otro']));

        expect($product->classifications()->count())->toBe(1);
    });
});

describe('baja', function () {
    it('elimina de forma lógica y conserva sus vínculos y su SKU', function () {
        $admin = userWithRole(RoleName::Admin);
        $classification = Classification::factory()->create();
        $product = app(SaveProduct::class)->handle(productPayload(['classification_ids' => [$classification->id]]), $admin);

        $this->actingAs($admin)
            ->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'));

        $this->assertSoftDeleted($product);
        expect($product->classifications()->count())->toBe(1);

        $this->get(route('products.show', $product))->assertNotFound();
        $this->get(route('products.index'))->assertDontSee('Lámpara solar');
    });

    it('borra los vínculos al eliminar una clasificación', function () {
        $classification = Classification::factory()->create();
        $product = Product::factory()->create();
        $product->classifications()->attach($classification);

        $classification->delete();

        expect(DB::table('classification_product')->count())->toBe(0);
        $this->assertModelExists($product);
    });

    it('permite consultar los productos de una clasificación', function () {
        $classification = Classification::factory()->create();
        $product = Product::factory()->create();
        $product->classifications()->attach($classification);

        expect($classification->products->modelKeys())->toBe([$product->id]);
    });
});

describe('estado por defecto del listado', function () {
    it('muestra al cliente solo los activos hasta que elija otro estado', function () {
        Product::factory()->create(['name' => 'Activo visible']);
        Product::factory()->inactive()->create(['name' => 'Inactivo oculto']);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Activo visible')
            ->assertDontSee('Inactivo oculto')
            ->assertSee('value="1" selected', false);

        $this->get(route('products.index', ['is_active' => 0]))
            ->assertSee('Inactivo oculto')->assertDontSee('Activo visible');

        $this->get(route('products.index', ['is_active' => '']))
            ->assertSee('Inactivo oculto')->assertSee('Activo visible');
    });

    it('muestra al administrador todos los productos por defecto', function () {
        Product::factory()->create(['name' => 'Activo visible']);
        Product::factory()->inactive()->create(['name' => 'Inactivo también']);

        $this->actingAs(userWithRole(RoleName::Admin))->get(route('products.index'))
            ->assertSee('Activo visible')->assertSee('Inactivo también');
    });
});

it('el seeder crea los productos de ejemplo una sola vez', function () {
    userWithRole(RoleName::Admin);
    $this->seed(ClassificationSeeder::class);

    $this->seed(ProductSeeder::class);
    $this->seed(ProductSeeder::class);

    expect(Product::count())->toBe(10)
        ->and(Product::whereNull('barcode')->count())->toBe(0)
        ->and(Product::where('sku', 'not like', 'PRD-%')->count())->toBe(0);
});
