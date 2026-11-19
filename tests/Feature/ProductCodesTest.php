<?php

use App\Actions\RenderBarcodeSvg;
use App\Actions\RenderQrSvg;
use App\Models\Product;
use App\Models\User;
use App\RoleName;

function isValidXml(string $content): bool
{
    return @(new DOMDocument)->loadXML($content) !== false;
}

describe('código de barras', function () {
    it('devuelve un SVG válido con las barras y los dígitos legibles', function () {
        $product = Product::factory()->withBarcode()->create();
        $code = $product->barcode;
        $digits = substr($code, 0, 1).' '.substr($code, 1, 6).' '.substr($code, 7, 6);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $response = $this->get(route('products.barcode', $product))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/svg+xml; charset=utf-8')
            ->and($response->headers->get('Content-Disposition'))->toBe('inline')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain('sandbox')
            ->and(isValidXml($response->getContent()))->toBeTrue()
            ->and(substr_count($response->getContent(), '<rect'))->toBeGreaterThan(20)
            ->and($response->getContent())->toContain($digits)->toContain("EAN-13 {$code}")
            ->and($response->getContent())->not->toContain('<script');
    });

    it('lo descarga con el SKU como nombre de archivo si se pide', function () {
        $product = Product::factory()->withBarcode()->create(['sku' => 'ABC-123']);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.barcode', ['product' => $product, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=abc-123-barcode.svg');
    });

    it('responde 404 si el producto no tiene código de barras o este no es válido', function () {
        $this->actingAs(userWithRole(RoleName::Cliente));
        $without = Product::factory()->create();
        $invalid = Product::factory()->create(['barcode' => '1234567890123']);

        $this->get(route('products.barcode', $without))->assertNotFound();
        $this->get(route('products.barcode', $invalid))->assertNotFound();
    });

    it('responde 404 si el producto fue eliminado', function () {
        $product = Product::factory()->withBarcode()->create();
        $product->delete();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.barcode', $product))->assertNotFound();
        $this->get(route('products.qr', $product))->assertNotFound();
    });
});

describe('código QR', function () {
    it('codifica la dirección de la ficha del producto', function () {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $response = $this->get(route('products.qr', $product))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/svg+xml; charset=utf-8')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and(isValidXml($response->getContent()))->toBeTrue()
            ->and($response->getContent())->toBe(app(RenderQrSvg::class)->handle(route('products.show', $product)))
            ->and($response->getContent())->not->toBe(app(RenderQrSvg::class)->handle(route('products.show', $other)));
    });

    it('funciona aunque el producto no tenga código de barras y se puede descargar', function () {
        $product = Product::factory()->create(['sku' => 'PRD-000007']);
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.qr', ['product' => $product, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=prd-000007-qr.svg');
    });
});

describe('acceso', function () {
    it('manda a iniciar sesión a quien no la tiene y rechaza a quien no tiene rol', function (string $route) {
        $product = Product::factory()->withBarcode()->create();

        $this->get(route($route, $product))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route($route, $product))->assertForbidden();
    })->with(['código de barras' => ['products.barcode'], 'QR' => ['products.qr']]);
});

describe('ficha del producto', function () {
    it('muestra el código de barras y el QR con sus enlaces de descarga', function () {
        $product = Product::factory()->withBarcode()->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee(route('products.barcode', $product), false)
            ->assertSee(route('products.qr', $product), false)
            ->assertSee('download=1', false)
            ->assertSee('Descargar código de barras (SVG)')
            ->assertSee('Descargar QR (SVG)');
    });

    it('avisa cuando no hay código de barras, pero conserva el QR', function () {
        $product = Product::factory()->create();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Este producto no tiene código de barras.')
            ->assertDontSee(route('products.barcode', $product), false)
            ->assertSee(route('products.qr', $product), false);
    });
});

describe('generadores', function () {
    it('dibuja un EAN-13 comercial con sus dígitos agrupados', function () {
        $svg = app(RenderBarcodeSvg::class)->handle('4006381333931');

        expect(isValidXml($svg))->toBeTrue()->and($svg)->toContain('4 006381 333931');
    });

    it('rechaza lo que no sea un EAN-13 válido', function (string $code) {
        expect(fn () => app(RenderBarcodeSvg::class)->handle($code))->toThrow(InvalidArgumentException::class);
    })->with(['dígito de control incorrecto' => ['4006381333932'], 'corto' => ['123'], 'con letras' => ['40063813339A1'], 'inyección' => ['"><script>']]);

    it('dibuja el QR con el tamaño pedido', function () {
        $svg = app(RenderQrSvg::class)->handle('https://ejemplo.test/products/lampara', 128);

        expect(isValidXml($svg))->toBeTrue()->and($svg)->toContain('width="128"')->toContain('<path');
    });
});
