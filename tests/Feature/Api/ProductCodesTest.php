<?php

use App\Actions\RenderQrSvg;
use App\Models\Product;
use App\Models\User;
use App\RoleName;
use Laravel\Sanctum\Sanctum;

it('responde 401 sin autenticación y 403 sin rol', function (string $suffix) {
    $product = Product::factory()->withBarcode()->create();

    $this->getJson("/api/products/{$product->slug}/{$suffix}")->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/products/{$product->slug}/{$suffix}")->assertForbidden();
})->with(['código de barras' => ['barcode.svg'], 'QR' => ['qr.svg']]);

it('entrega el código de barras como SVG, también como descarga', function () {
    $product = Product::factory()->withBarcode()->create(['sku' => 'LMP-001']);
    Sanctum::actingAs(userWithRole(RoleName::Cliente));

    $response = $this->get("/api/products/{$product->slug}/barcode.svg")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/svg+xml; charset=utf-8')
        ->and($response->getContent())->toContain("EAN-13 {$product->barcode}");

    $this->get("/api/products/{$product->slug}/barcode.svg?download=1")
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=lmp-001-barcode.svg');
});

it('entrega el QR de la ficha del producto', function () {
    $product = Product::factory()->create();
    Sanctum::actingAs(userWithRole(RoleName::Cliente));

    $this->get("/api/products/{$product->slug}/qr.svg")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');

    expect($this->get("/api/products/{$product->slug}/qr.svg")->getContent())
        ->toBe(app(RenderQrSvg::class)->handle(route('products.show', $product)));
});

it('responde 404 en JSON si no hay código de barras o el producto fue eliminado', function () {
    Sanctum::actingAs(userWithRole(RoleName::Cliente));
    $without = Product::factory()->create();
    $deleted = Product::factory()->withBarcode()->create();
    $deleted->delete();

    $this->getJson("/api/products/{$without->slug}/barcode.svg")->assertNotFound()->assertJsonStructure(['message']);
    $this->getJson("/api/products/{$deleted->slug}/barcode.svg")->assertNotFound();
    $this->getJson("/api/products/{$deleted->slug}/qr.svg")->assertNotFound();
});
