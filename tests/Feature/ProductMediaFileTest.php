<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => Storage::fake('local'));

it('sirve una foto sin sesión, con caché de un año y sin adivinar el tipo', function () {
    $name = Str::random(40).'.webp';
    Storage::disk('local')->put("products/{$name}", 'contenido-webp');

    $response = $this->get(route('products.media.file', $name))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')->toContain('public')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
});

it('sirve cada extensión con su tipo', function (string $extension, string $type) {
    $name = Str::random(40).'.'.$extension;
    Storage::disk('local')->put("products/{$name}", 'contenido');

    expect($this->get(route('products.media.file', $name))->assertOk()->headers->get('Content-Type'))->toBe($type);
})->with([
    'mp4' => ['mp4', 'video/mp4'],
    'webm' => ['webm', 'video/webm'],
]);

it('admite peticiones por rangos para poder adelantar y retroceder un video', function () {
    $name = Str::random(40).'.mp4';
    Storage::disk('local')->put("products/{$name}", str_repeat('0123456789', 10));

    $response = $this->get(route('products.media.file', $name), ['Range' => 'bytes=10-19']);

    $response->assertStatus(206);
    expect($response->headers->get('Content-Range'))->toBe('bytes 10-19/100')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->streamedContent())->toBe('0123456789');
});

it('responde 404 si el archivo no existe', function () {
    $this->get(route('products.media.file', Str::random(40).'.webp'))->assertNotFound();
});

it('no acepta nombres fuera del formato esperado', function (string $filename) {
    Storage::disk('local')->put('products/corto.webp', 'x');
    Storage::disk('local')->put('products/'.str_repeat('a', 40).'.php', 'x');

    $this->get('/media/products/'.$filename)->assertNotFound();
})->with([
    'muy corto' => ['corto.webp'],
    'extensión no permitida' => [str_repeat('a', 40).'.php'],
    'extensión en mayúsculas' => [str_repeat('a', 40).'.WEBP'],
    'caracteres fuera del alfabeto' => [str_repeat('a', 39).'-.webp'],
    'salir de la carpeta' => ['..%2Fusers%2F'.str_repeat('a', 20).'.webp'],
]);
