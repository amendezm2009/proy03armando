<?php

use App\Actions\AddProductMedia;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\RoleName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    fakeProbe(30.0);
});

function apiPhoto(string $name = 'foto.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 600, 400);
}

describe('acceso', function () {
    it('responde 401 sin autenticación y 403 sin rol', function () {
        $product = Product::factory()->create();

        $this->getJson("/api/products/{$product->slug}/media")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/products/{$product->slug}/media")->assertForbidden();
    });

    it('permite al cliente consultar la multimedia y la ve en el producto', function () {
        $product = Product::factory()->create();
        app(AddProductMedia::class)->handle($product, [apiPhoto()]);
        Sanctum::actingAs(userWithRole(RoleName::Cliente));

        $this->getJson("/api/products/{$product->slug}/media")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_cover', true)
            ->assertJsonStructure(['data' => [['id', 'type', 'url', 'thumbnail_url', 'original_name', 'mime_type', 'size_bytes', 'duration_seconds', 'position', 'is_cover']]]);

        $this->getJson("/api/products/{$product->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.cover_url', $product->fresh()->cover->thumbnailUrl());
    });

    it('responde 403 en JSON al cliente en las acciones de escritura', function (string $method, string $uri) {
        $product = Product::factory()->create();
        $media = ProductMedia::factory()->for($product)->create();
        Sanctum::actingAs(userWithRole(RoleName::Cliente));

        $this->json($method, str_replace(['{product}', '{media}'], [$product->slug, $media->id], $uri))
            ->assertForbidden()
            ->assertJsonStructure(['message']);

        $this->assertModelExists($media);
    })->with([
        'subir' => ['POST', '/api/products/{product}/media'],
        'ordenar' => ['PUT', '/api/products/{product}/media/order'],
        'portada' => ['PATCH', '/api/products/{product}/media/{media}/cover'],
        'eliminar' => ['DELETE', '/api/products/{product}/media/{media}'],
    ]);
});

describe('escritura', function () {
    beforeEach(fn () => Sanctum::actingAs(userWithRole(RoleName::Admin)));

    it('sube archivos y devuelve 201 con su descripción', function () {
        $product = Product::factory()->create();

        $response = $this->withHeader('Accept', 'application/json')
            ->post("/api/products/{$product->slug}/media", ['media' => [apiPhoto('a.jpg'), fakeMp4()]])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'image')
            ->assertJsonPath('data.0.is_cover', true)
            ->assertJsonPath('data.0.position', 0)
            ->assertJsonPath('data.1.type', 'video')
            ->assertJsonPath('data.1.thumbnail_url', null)
            ->assertJsonPath('data.1.duration_seconds', 30)
            ->assertJsonPath('data.1.is_cover', false);

        expect($response->json('data.0.url'))->toContain('/media/products/')
            ->and($product->media()->count())->toBe(2);
    });

    it('responde 422 con archivos inválidos o al pasar de 9', function () {
        $product = Product::factory()->create();

        $this->withHeader('Accept', 'application/json')
            ->post("/api/products/{$product->slug}/media", ['media' => [realUpload('texto', 'a.txt')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media.0');

        $this->withHeader('Accept', 'application/json')
            ->post("/api/products/{$product->slug}/media", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media');

        $full = Product::factory()->create();
        ProductMedia::factory()->count(9)->for($full)->sequence(fn ($s) => ['position' => $s->index])->create();

        $this->withHeader('Accept', 'application/json')
            ->post("/api/products/{$full->slug}/media", ['media' => [apiPhoto()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media');

        expect($product->media()->count())->toBe(0)->and($full->media()->count())->toBe(9);
    });

    it('cambia el orden con la lista completa de ids y rechaza listas incompletas o ajenas', function () {
        $product = Product::factory()->create();
        [$a, $b, $c] = app(AddProductMedia::class)->handle($product, [apiPhoto('a.jpg'), apiPhoto('b.jpg'), apiPhoto('c.jpg')])->all();
        $foreign = ProductMedia::factory()->create();

        $this->putJson("/api/products/{$product->slug}/media/order", ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.2.id', $b->id);
        expect($product->media()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);

        foreach ([[$a->id, $b->id], [$a->id, $b->id, $foreign->id], [$a->id, $a->id, $b->id]] as $ids) {
            $this->putJson("/api/products/{$product->slug}/media/order", ['ids' => $ids])->assertUnprocessable();
        }
        expect($product->media()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
    });

    it('elige la portada solo entre las fotos', function () {
        $product = Product::factory()->create();
        [$a, $b, $video] = app(AddProductMedia::class)->handle($product, [apiPhoto('a.jpg'), apiPhoto('b.jpg'), fakeMp4()])->all();

        $this->patchJson("/api/products/{$product->slug}/media/{$b->id}/cover")
            ->assertOk()
            ->assertJsonPath('data.is_cover', true);
        expect($product->fresh()->cover_media_id)->toBe($b->id);

        $this->patchJson("/api/products/{$product->slug}/media/{$video->id}/cover")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media');
        expect($product->fresh()->cover_media_id)->toBe($b->id);
    });

    it('elimina un archivo con sus ficheros y responde 204', function () {
        $product = Product::factory()->create();
        [$a, $b] = app(AddProductMedia::class)->handle($product, [apiPhoto('a.jpg'), apiPhoto('b.jpg')])->all();

        $this->deleteJson("/api/products/{$product->slug}/media/{$a->id}")->assertNoContent();

        expect(ProductMedia::find($a->id))->toBeNull()
            ->and(Storage::disk('local')->exists('products/'.$a->path))->toBeFalse()
            ->and($product->fresh()->cover_media_id)->toBe($b->id)
            ->and($b->fresh()->position)->toBe(0);
    });

    it('responde 404 si el archivo es de otro producto', function (string $method, string $suffix) {
        $product = Product::factory()->create();
        $foreign = ProductMedia::factory()->create();

        $this->json($method, "/api/products/{$product->slug}/media/{$foreign->id}{$suffix}")->assertNotFound();

        $this->assertModelExists($foreign);
    })->with([
        'eliminar' => ['DELETE', ''],
        'portada' => ['PATCH', '/cover'],
    ]);
});

describe('listado de productos', function () {
    it('incluye la portada sin una consulta por producto', function () {
        Sanctum::actingAs(userWithRole(RoleName::Cliente));
        $withCover = function (int $count): void {
            Product::factory()->count($count)->create()->each(function (Product $product): void {
                $media = ProductMedia::factory()->for($product)->create();
                $product->forceFill(['cover_media_id' => $media->id])->save();
            });
        };

        $withCover(2);
        $this->getJson('/api/products')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/products')->assertOk();
        $few = count(DB::getQueryLog());

        $withCover(6);
        DB::flushQueryLog();
        $response = $this->getJson('/api/products')->assertOk()->assertJsonCount(8, 'data');

        expect(count(DB::getQueryLog()))->toBe($few)
            ->and($response->json('data.0.cover_url'))->toContain('/media/products/');
    });
});
