<?php

use App\Actions\AddProductMedia;
use App\Actions\RenderQrSvg;
use App\Models\Classification;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\RoleName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Todas las rutas de productos, web y API, con la dirección ya resuelta (excepto la de archivos públicos).
 *
 * @return list<array{0: string, 1: string, 2: bool}> [método, dirección, ¿es de la API?]
 */
function productRoutes(Product $product, ProductMedia $media): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'products.') || str_starts_with((string) $route->getName(), 'api.products.'))
        ->reject(fn ($route) => $route->getName() === 'products.media.file')
        ->map(fn ($route): array => [
            $route->methods()[0],
            '/'.str_replace(['{product}', '{media}'], [$product->slug, (string) $media->id], $route->uri()),
            str_starts_with($route->uri(), 'api/'),
        ])
        ->values()
        ->all();
}

describe('autorización de todas las rutas', function () {
    it('exige sesión y, sin rol, responde 403 en cada ruta de productos', function () {
        $product = Product::factory()->withBarcode()->create();
        $media = ProductMedia::factory()->for($product)->create();
        $routes = productRoutes($product, $media);

        // Si se agrega una ruta de productos, este número cambia y hay que revisar que quede protegida.
        expect($routes)->toHaveCount(25);

        foreach ($routes as [$method, $uri]) {
            $this->assertSame(401, $this->json($method, $uri)->status(), "Sin sesión: {$method} {$uri}");
        }

        $noRole = User::factory()->create();

        $this->actingAs($noRole);
        foreach (array_filter($routes, fn (array $route) => ! $route[2]) as [$method, $uri]) {
            $this->assertSame(403, $this->json($method, $uri)->status(), "Sin rol (web): {$method} {$uri}");
        }

        Sanctum::actingAs($noRole);
        foreach (array_filter($routes, fn (array $route) => $route[2]) as [$method, $uri]) {
            $this->assertSame(403, $this->json($method, $uri)->status(), "Sin rol (API): {$method} {$uri}");
        }

        $this->assertNotSoftDeleted($product);
        $this->assertModelExists($media);
    });

    it('rechaza al cliente en cada ruta de escritura de productos', function () {
        $product = Product::factory()->withBarcode()->create();
        $media = ProductMedia::factory()->for($product)->create();
        $writes = array_filter(productRoutes($product, $media), fn (array $route) => ! in_array($route[0], ['GET', 'HEAD'], true));
        $cliente = userWithRole(RoleName::Cliente);

        $this->actingAs($cliente);
        foreach (array_filter($writes, fn (array $route) => ! $route[2]) as [$method, $uri]) {
            $this->assertSame(403, $this->json($method, $uri)->status(), "Cliente (web): {$method} {$uri}");
        }

        Sanctum::actingAs($cliente);
        foreach (array_filter($writes, fn (array $route) => $route[2]) as [$method, $uri]) {
            $this->assertSame(403, $this->json($method, $uri)->status(), "Cliente (API): {$method} {$uri}");
        }

        expect($writes)->not->toBeEmpty();
        $this->assertNotSoftDeleted($product);
        $this->assertModelExists($media);
    });
});

describe('datos que no deben salir', function () {
    it('la API muestra el precio de compra solo a quien puede editar productos', function () {
        $product = Product::factory()->create(['purchase_price' => 80, 'sale_price' => 120]);

        Sanctum::actingAs(userWithRole(RoleName::Cliente));
        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonMissingPath('data.0.purchase_price')
            ->assertJsonPath('data.0.sale_price', 120);
        $this->getJson("/api/products/{$product->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.purchase_price');

        app('auth')->forgetGuards();
        Sanctum::actingAs(userWithRole(RoleName::Admin));
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.purchase_price', 80);
        $this->getJson("/api/products/{$product->slug}")->assertOk()->assertJsonPath('data.purchase_price', 80);
    });

    it('la ficha web tampoco muestra el precio de compra al cliente', function () {
        $product = Product::factory()->create(['purchase_price' => 80.55, 'sale_price' => 120]);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('Precio de compra')
            ->assertDontSee('80.55');
    });
});

describe('contenido malicioso', function () {
    it('escapa nombres, descripciones, etiquetas y archivos al mostrarlos', function () {
        $classification = Classification::factory()->create(['name' => '<b>etiqueta</b>']);
        $product = Product::factory()->create([
            'name' => '<script>alert(1)</script>',
            'description' => '<img src=x onerror=alert(1)>',
        ]);
        $product->classifications()->attach($classification);
        ProductMedia::factory()->for($product)->create(['original_name' => '"><svg onload=alert(1)>.jpg']);
        $this->actingAs(userWithRole(RoleName::Admin));

        foreach ([route('products.index'), route('products.show', $product), route('products.edit', $product)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            expect($html)->not->toContain('<script>alert(1)</script>')
                ->not->toContain('<img src=x onerror')
                ->not->toContain('<b>etiqueta</b>')
                ->not->toContain('"><svg onload');
        }

        $this->get(route('products.show', $product))->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });

    it('trata la búsqueda como texto y no como SQL', function (string $term) {
        Product::factory()->create(['name' => 'Producto normal']);
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->get(route('products.index', ['search' => $term]))
            ->assertOk()
            ->assertDontSee('Producto normal');

        expect(Product::count())->toBe(1);
    })->with([
        'comilla y OR' => ["' OR 1=1 --"],
        'borrado' => ["'; DROP TABLE products; --"],
        'UNION' => ['" UNION SELECT * FROM users --'],
    ]);

    it('rechaza filtros de la API con forma de inyección', function (string $query) {
        Sanctum::actingAs(userWithRole(RoleName::Cliente));

        $this->getJson("/api/products?{$query}")->assertUnprocessable();
    })->with([
        'clasificación' => ['classifications[]=1%20OR%201=1'],
        'existencias' => ['stock_status=%27%20OR%201=1'],
        'precio' => ['price_min=0%3B%20DROP%20TABLE%20products'],
    ]);
});

describe('archivos y códigos públicos', function () {
    it('sirve los archivos con una política de contenido que impide ejecutar nada', function () {
        Storage::fake('local');
        $name = Str::random(40).'.webp';
        Storage::disk('local')->put("products/{$name}", 'contenido');

        $this->get(route('products.media.file', $name))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('el QR usa la dirección configurada y no el dominio de la petición', function () {
        config(['app.url' => 'https://tienda.example']);
        Product::factory()->create(['slug' => 'lampara']);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $svg = $this->get('http://evil.test/products/lampara/qr.svg')->assertOk()->getContent();

        expect($svg)->toBe(app(RenderQrSvg::class)->handle('https://tienda.example/invitado/catalogo/lampara'))
            ->and($svg)->not->toBe(app(RenderQrSvg::class)->handle('http://evil.test/invitado/catalogo/lampara'));
    });
});

describe('registro y limpieza', function () {
    it('registra en el log quién elimina un producto, por la web y por la API', function () {
        $admin = userWithRole(RoleName::Admin);
        [$web, $api] = Product::factory()->count(2)->create()->all();
        Log::spy();

        $this->actingAs($admin)->delete(route('products.destroy', $web))->assertRedirect();
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/products/{$api->slug}")->assertNoContent();

        foreach ([$web, $api] as $product) {
            Log::shouldHaveReceived('info')
                ->with('product.deleted', ['product_id' => $product->id, 'actor_id' => $admin->id, 'permanent' => false])
                ->once();
        }
    });

    it('al eliminar definitivamente un producto borra sus archivos y deja los de los demás', function () {
        Storage::fake('local');
        fakeProbe(30.0);
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        app(AddProductMedia::class)->handle($product, [UploadedFile::fake()->image('a.jpg'), fakeMp4()]);
        app(AddProductMedia::class)->handle($other, [UploadedFile::fake()->image('b.jpg')]);

        expect(Storage::disk('local')->allFiles('products'))->toHaveCount(5);

        $product->delete();
        expect(Storage::disk('local')->allFiles('products'))->toHaveCount(5);

        $product->forceDelete();

        expect(Storage::disk('local')->allFiles('products'))->toHaveCount(2)
            ->and(ProductMedia::where('product_id', $product->id)->count())->toBe(0)
            ->and(ProductMedia::where('product_id', $other->id)->count())->toBe(1);
    });
});
