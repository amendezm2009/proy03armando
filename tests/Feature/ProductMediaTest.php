<?php

use App\Actions\AddProductMedia;
use App\Actions\ProbeVideo;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\RoleName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    fakeProbe(30.0);
});

/**
 * @return list<string>
 */
function mediaFilesOnDisk(): array
{
    return Storage::disk('local')->allFiles('products');
}

function mediaPhoto(string $name = 'foto.jpg', int $width = 600, int $height = 400): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

/**
 * Producto con filas de multimedia ya creadas (sin archivos en el disco).
 */
function productWithMediaRows(int $count): Product
{
    $product = Product::factory()->create();
    ProductMedia::factory()->count($count)->for($product)
        ->sequence(fn ($sequence) => ['position' => $sequence->index])->create();

    return $product->fresh();
}

/**
 * Un MP4 mínimo que solo trae la cabecera con la duración: lo justo para que getID3 la lea.
 */
function minimalMp4(int $seconds): string
{
    $mvhd = pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', $seconds * 1000)
        .pack('N', 0x00010000).pack('n', 0x0100).str_repeat("\0", 10)
        .pack('N*', 0x00010000, 0, 0, 0, 0x00010000, 0, 0, 0, 0x40000000)
        .str_repeat("\0", 24).pack('N', 2);
    $mvhdBox = pack('N', 8 + strlen($mvhd)).'mvhd'.$mvhd;
    $moov = pack('N', 8 + strlen($mvhdBox)).'moov'.$mvhdBox;

    return pack('N', 24).'ftypisom'.pack('N', 512).'isomiso2'.$moov;
}

describe('subida', function () {
    it('guarda fotos reducidas con su miniatura y deja la primera como portada', function () {
        $admin = userWithRole(RoleName::Admin);
        $product = Product::factory()->create();

        $this->actingAs($admin)
            ->post(route('products.media.store', $product), [
                'media' => [mediaPhoto('Mi Foto.JPG', 3000, 2000), mediaPhoto('segunda.png', 400, 400)],
            ])
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('success', '2 archivos agregados.');

        $media = $product->fresh()->media;
        $disk = Storage::disk('local');
        [$width] = getimagesize($disk->path('products/'.$media[0]->path));
        $thumbnail = getimagesize($disk->path('products/'.$media[0]->thumbnail_path));

        expect($media)->toHaveCount(2)
            ->and($media->pluck('position')->all())->toBe([0, 1])
            ->and($media->pluck('type')->unique()->all())->toBe(['image'])
            ->and($media[0]->original_name)->toBe('Mi Foto.JPG')
            ->and($media[0]->path)->toMatch('/^[A-Za-z0-9]{40}\.webp$/')
            ->and($media[0]->mime_type)->toBe('image/webp')
            ->and($width)->toBe(1600)
            ->and([$thumbnail[0], $thumbnail[1]])->toBe([320, 320])
            ->and($product->fresh()->cover_media_id)->toBe($media[0]->id)
            ->and(mediaFilesOnDisk())->toHaveCount(4);
    });

    it('guarda los videos tal cual, con su duración, sin miniatura y sin tomarlos como portada', function () {
        fakeProbe(61.2);
        $product = Product::factory()->create();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.media.store', $product), ['media' => [fakeMp4(), fakeWebm()]])
            ->assertSessionHasNoErrors();

        $media = $product->fresh()->media;

        expect($media->pluck('type')->all())->toBe(['video', 'video'])
            ->and($media[0]->path)->toEndWith('.mp4')
            ->and($media[1]->path)->toEndWith('.webm')
            ->and($media[0]->duration_seconds)->toBe(62)
            ->and($media[0]->durationLabel())->toBe('1:02')
            ->and($media[0]->thumbnail_path)->toBeNull()
            ->and($product->fresh()->cover_media_id)->toBeNull();

        $this->post(route('products.media.store', $product), ['media' => [mediaPhoto()]]);

        expect($product->fresh()->cover_media_id)->toBe($product->media()->where('type', 'image')->value('id'));
    });

    it('no cambia la portada existente al subir más fotos', function () {
        $product = Product::factory()->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->post(route('products.media.store', $product), ['media' => [mediaPhoto('a.jpg')]]);
        $cover = $product->fresh()->cover_media_id;
        $this->post(route('products.media.store', $product), ['media' => [mediaPhoto('b.jpg')]]);

        expect($product->fresh()->cover_media_id)->toBe($cover)
            ->and($product->media()->pluck('position')->all())->toBe([0, 1]);
    });

    it('no admite más de 9 archivos entre fotos y videos', function () {
        $product = productWithMediaRows(8);
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->post(route('products.media.store', $product), ['media' => [mediaPhoto(), mediaPhoto('b.jpg')]])
            ->assertSessionHasErrors('media');

        expect($product->media()->count())->toBe(8)->and(mediaFilesOnDisk())->toBe([]);

        $this->post(route('products.media.store', $product), ['media' => [mediaPhoto()]])->assertSessionHasNoErrors();
        expect($product->media()->count())->toBe(9);

        $this->post(route('products.media.store', $product), ['media' => [mediaPhoto()]])->assertSessionHasErrors('media');
        expect($product->media()->count())->toBe(9);
    });

    it('rechaza diez archivos en una sola petición y acepta nueve', function () {
        $product = Product::factory()->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->post(route('products.media.store', $product), [
            'media' => array_map(fn (int $i) => mediaPhoto("f{$i}.jpg", 80, 80), range(1, 10)),
        ])->assertSessionHasErrors('media');
        expect($product->media()->count())->toBe(0);

        $this->post(route('products.media.store', $product), [
            'media' => array_map(fn (int $i) => mediaPhoto("f{$i}.jpg", 80, 80), range(1, 9)),
        ])->assertSessionHasNoErrors();
        expect($product->media()->count())->toBe(9);
    });

    it('rechaza archivos no admitidos, pesados o dañados', function (Closure $makeFile, string $message) {
        $product = Product::factory()->create();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.media.store', $product), ['media' => [$makeFile()]])
            ->assertSessionHasErrors('media.0');

        expect(session('errors')->first('media.0'))->toContain($message)
            ->and($product->media()->count())->toBe(0)
            ->and(mediaFilesOnDisk())->toBe([]);
    })->with([
        'texto' => [fn () => realUpload('hola mundo', 'notas.txt'), 'Solo se admiten'],
        'html disfrazado de foto' => [fn () => realUpload('<html><script>alert(1)</script></html>', 'foto.jpg'), 'Solo se admiten'],
        'gif' => [fn () => realUpload("GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'animacion.gif'), 'Solo se admiten'],
        'foto de más de 2 MB' => [fn () => mediaPhoto('grande.jpg')->size(3000), 'como máximo 2 MB'],
        'foto dañada' => [fn () => realUpload("\xFF\xD8\xFF\xE0".str_repeat('x', 200), 'rota.jpg'), 'no se pudo leer'],
        'video de 10 MB' => [fn () => fakeMp4(10 * 1024 * 1024), 'menos de 10 MB'],
    ]);

    it('acepta una foto de exactamente 2 MB y un video de un byte menos de 10 MB', function () {
        $product = Product::factory()->create();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.media.store', $product), [
                'media' => [mediaPhoto('limite.jpg')->size(2048), fakeMp4(10 * 1024 * 1024 - 1)],
            ])
            ->assertSessionHasNoErrors();

        expect($product->media()->count())->toBe(2);
    });

    it('limita los videos a 5 minutos y rechaza los que no se pueden leer', function () {
        $product = Product::factory()->create();
        $this->actingAs(userWithRole(RoleName::Admin));

        fakeProbe(300.0);
        $this->post(route('products.media.store', $product), ['media' => [fakeMp4()]])->assertSessionHasNoErrors();

        fakeProbe(300.5);
        $this->post(route('products.media.store', $product), ['media' => [fakeMp4()]])->assertSessionHasErrors('media.0');
        expect(session('errors')->first('media.0'))->toContain('como máximo 5 minutos');

        fakeProbe(null);
        $this->post(route('products.media.store', $product), ['media' => [fakeMp4()]])->assertSessionHasErrors('media.0');
        expect(session('errors')->first('media.0'))->toContain('no se pudo leer');

        expect($product->media()->count())->toBe(1);
    });

    it('exige elegir al menos un archivo', function () {
        $product = Product::factory()->create();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('products.media.store', $product), [])
            ->assertSessionHasErrors('media');
    });

    it('borra los archivos ya escritos si uno de la tanda falla', function () {
        $product = Product::factory()->create();
        $broken = realUpload("\xFF\xD8\xFF\xE0".str_repeat('x', 200), 'rota.jpg');

        expect(fn () => app(AddProductMedia::class)->handle($product, [mediaPhoto(), $broken]))
            ->toThrow(ValidationException::class);

        expect(mediaFilesOnDisk())->toBe([])
            ->and($product->media()->count())->toBe(0)
            ->and($product->fresh()->cover_media_id)->toBeNull();
    });

    it('vuelve a comprobar el límite al guardar, aunque la validación se haya saltado', function () {
        $product = productWithMediaRows(9);

        expect(fn () => app(AddProductMedia::class)->handle($product, [mediaPhoto()]))
            ->toThrow(ValidationException::class);

        expect($product->media()->count())->toBe(9)->and(mediaFilesOnDisk())->toBe([]);
    });
});

describe('acceso', function () {
    it('deja ver la galería al cliente sin controles de gestión', function () {
        $product = productWithMediaRows(2);
        $media = $product->media->first();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee($media->thumbnailUrl(), false)
            ->assertDontSee('Agregar fotos o videos')
            ->assertDontSee('class="js-delete-media"', false)
            ->assertDontSee('Usar como portada');
    });

    it('rechaza al cliente en las acciones de gestión', function (string $method, string $uri) {
        $product = productWithMediaRows(1);
        $media = $product->media->first();
        $this->actingAs(userWithRole(RoleName::Cliente));

        $this->call($method, str_replace(['{product}', '{media}'], [$product->slug, $media->id], $uri))->assertForbidden();

        expect($product->media()->count())->toBe(1);
    })->with([
        'subir' => ['POST', '/products/{product}/media'],
        'eliminar' => ['DELETE', '/products/{product}/media/{media}'],
        'portada' => ['PATCH', '/products/{product}/media/{media}/cover'],
        'mover' => ['PATCH', '/products/{product}/media/{media}/move'],
    ]);

    it('rechaza a quien no tiene rol y manda a iniciar sesión a quien no la tiene', function () {
        $product = Product::factory()->create();

        $this->post(route('products.media.store', $product), ['media' => [mediaPhoto()]])->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->post(route('products.media.store', $product), ['media' => [mediaPhoto()]])
            ->assertForbidden();
    });

    it('muestra al administrador el formulario y los controles, y los oculta al llegar a 9', function () {
        $product = productWithMediaRows(3);
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Agregar fotos o videos')
            ->assertSee('class="js-delete-media"', false)
            ->assertSee('Puedes agregar 6 más');

        $full = productWithMediaRows(9);

        $this->get(route('products.show', $full))
            ->assertOk()
            ->assertDontSee('Agregar fotos o videos')
            ->assertSee('Llegaste al máximo de 9 archivos');
    });
});

describe('portada y orden', function () {
    it('cambia la portada a otra foto, pero no a un video', function () {
        $product = Product::factory()->create();
        [$a, $b, $video] = app(AddProductMedia::class)->handle($product, [mediaPhoto('a.jpg'), mediaPhoto('b.jpg'), fakeMp4()])->all();
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->patch(route('products.media.cover', [$product, $b]))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('success', 'Portada actualizada.');
        expect($product->fresh()->cover_media_id)->toBe($b->id);

        $this->patch(route('products.media.cover', [$product, $video]))->assertSessionHasErrors('media');
        expect($product->fresh()->cover_media_id)->toBe($b->id);
    });

    it('mueve un archivo antes o después y no hace nada en los extremos', function () {
        $product = Product::factory()->create();
        [$a, $b, $c] = app(AddProductMedia::class)->handle($product, [mediaPhoto('a.jpg'), mediaPhoto('b.jpg'), mediaPhoto('c.jpg')])->all();
        $this->actingAs(userWithRole(RoleName::Admin));
        $order = fn (): array => $product->media()->pluck('id')->all();

        $this->patch(route('products.media.move', [$product, $a]), ['direction' => 'down']);
        expect($order())->toBe([$b->id, $a->id, $c->id]);

        $this->patch(route('products.media.move', [$product, $c]), ['direction' => 'down']);
        expect($order())->toBe([$b->id, $a->id, $c->id]);

        $this->patch(route('products.media.move', [$product, $c]), ['direction' => 'up']);
        expect($order())->toBe([$b->id, $c->id, $a->id]);

        $this->patch(route('products.media.move', [$product, $b]), ['direction' => 'up']);
        expect($order())->toBe([$b->id, $c->id, $a->id]);

        $this->patch(route('products.media.move', [$product, $b]), ['direction' => 'sideways'])->assertSessionHasErrors('direction');
    });

    it('responde 404 si el archivo es de otro producto', function (string $method, string $route, array $data) {
        $product = Product::factory()->create();
        $foreign = productWithMediaRows(1)->media->first();
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->call($method, route($route, [$product, $foreign]), $data)->assertNotFound();

        $this->assertModelExists($foreign);
    })->with([
        'eliminar' => ['DELETE', 'products.media.destroy', []],
        'portada' => ['PATCH', 'products.media.cover', []],
        'mover' => ['PATCH', 'products.media.move', ['direction' => 'down']],
    ]);
});

describe('eliminar', function () {
    it('borra la fila y los archivos, renumera y reasigna la portada', function () {
        $product = Product::factory()->create();
        [$a, $b, $video] = app(AddProductMedia::class)->handle($product, [mediaPhoto('a.jpg'), mediaPhoto('b.jpg'), fakeMp4()])->all();
        $other = Product::factory()->create();
        app(AddProductMedia::class)->handle($other, [mediaPhoto('otra.jpg')]);
        $disk = Storage::disk('local');
        $this->actingAs(userWithRole(RoleName::Admin));

        expect($product->fresh()->cover_media_id)->toBe($a->id);

        $this->delete(route('products.media.destroy', [$product, $a]))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('success', 'Archivo eliminado.');

        expect($disk->exists('products/'.$a->path))->toBeFalse()
            ->and($disk->exists('products/'.$a->thumbnail_path))->toBeFalse()
            ->and(ProductMedia::find($a->id))->toBeNull()
            ->and($product->fresh()->cover_media_id)->toBe($b->id)
            ->and($product->media()->pluck('position', 'id')->all())->toBe([$b->id => 0, $video->id => 1])
            ->and(mediaFilesOnDisk())->toHaveCount(5);

        $this->delete(route('products.media.destroy', [$product, $b]));

        expect($product->fresh()->cover_media_id)->toBeNull()
            ->and($product->media()->pluck('position', 'id')->all())->toBe([$video->id => 0]);

        $this->delete(route('products.media.destroy', [$product, $video]));

        expect($product->media()->count())->toBe(0)
            ->and(mediaFilesOnDisk())->toHaveCount(2);
    });

    it('conserva la multimedia al eliminar el producto y la borra de la base al eliminarlo definitivamente', function () {
        $product = productWithMediaRows(2);

        $product->delete();
        expect(ProductMedia::count())->toBe(2);

        $product->forceDelete();
        expect(ProductMedia::count())->toBe(0);
    });
});

describe('listado y ficha', function () {
    it('muestra la duración de los videos y marca la portada en la ficha', function () {
        $product = Product::factory()->create();
        app(AddProductMedia::class)->handle($product, [mediaPhoto(), fakeMp4()]);
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->get(route('products.show', $product->fresh()))
            ->assertOk()
            ->assertSee('Portada')
            ->assertSee('0:30')
            ->assertSee('2 de 9')
            ->assertSee('#t=0.1" preload="metadata"', false);
    });

    it('muestra la portada en miniatura en el listado sin hacer consultas de más', function () {
        $this->actingAs(userWithRole(RoleName::Admin));
        $withCover = function (int $count): void {
            Product::factory()->count($count)->create()->each(function (Product $product): void {
                $media = ProductMedia::factory()->for($product)->create();
                $product->forceFill(['cover_media_id' => $media->id])->save();
            });
        };

        $withCover(2);
        $this->get(route('products.index'))->assertOk();

        DB::enableQueryLog();
        $this->get(route('products.index'))->assertOk();
        $few = count(DB::getQueryLog());

        $withCover(6);
        DB::flushQueryLog();
        $response = $this->get(route('products.index'))->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
        $response->assertSee(ProductMedia::first()->thumbnailUrl(), false);
    });
});

describe('lector de duración', function () {
    beforeEach(fn () => app()->forgetInstance(ProbeVideo::class));

    it('lee la duración de un MP4 con getID3', function () {
        $path = tempnam(sys_get_temp_dir(), 'mp4');
        file_put_contents($path, minimalMp4(12));

        $seconds = (new ProbeVideo)->durationSeconds($path);
        unlink($path);

        expect($seconds)->toBe(12.0);
    });

    it('devuelve null con un archivo que no es un video o que no existe', function () {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, str_repeat('no soy un video ', 100));

        expect((new ProbeVideo)->durationSeconds($path))->toBeNull()
            ->and((new ProbeVideo)->durationSeconds($path.'.no-existe'))->toBeNull();

        unlink($path);
    });
});
