<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Rules\ProductMediaFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Agrega fotos y videos a un producto.
 *
 * - Una foto se decodifica de verdad, se orienta según EXIF, se reduce a image_max_side px y se guarda como
 *   WebP junto a una miniatura cuadrada (también WebP): al volver a codificarla se pierden los metadatos
 *   (GPS, cámara) y cualquier contenido ajeno al píxel. Un video se guarda tal cual (no se puede reprocesar
 *   sin ffmpeg); su tipo y duración ya los validó App\Rules\ProductMediaFile.
 * - Los nombres de archivo los genera el servidor (40 caracteres aleatorios); el original solo se guarda
 *   como texto, para mostrarlo.
 * - Se guardan en el disco privado (carpeta "products", sin "storage:link"); los sirve ProductMediaFileController.
 * - Se revisa el límite de archivos dentro de una transacción con el producto bloqueado, para que dos
 *   subidas a la vez no lo sobrepasen. Si algo falla se borran los archivos ya escritos.
 * - Si el producto aún no tiene portada, la primera foto subida pasa a serlo.
 *
 * USO: app(AddProductMedia::class)->handle($product, $request->file('media'));
 */
class AddProductMedia
{
    public const string DISK = 'local';

    public const string DIRECTORY = 'products';

    private const int QUALITY = 85;

    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, ProductMedia>
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $files): Collection
    {
        $stored = [];

        try {
            $created = DB::transaction(function () use ($product, $files, &$stored): Collection {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $max = (int) config('catalog.media.max_files');
                $existing = $locked->media()->count();

                if ($existing + count($files) > $max) {
                    throw ValidationException::withMessages(['media' => __('validation.media_max_files', ['max' => $max])]);
                }

                $position = $existing === 0 ? 0 : (int) $locked->media()->max('position') + 1;
                $created = collect();

                foreach ($files as $file) {
                    $created->push($this->storeOne($locked, $file, $position++, $stored));
                }

                $firstImage = $created->first(fn (ProductMedia $media): bool => $media->isImage());

                if ($locked->cover_media_id === null && $firstImage !== null) {
                    $locked->forceFill(['cover_media_id' => $firstImage->id])->save();
                }

                return $created;
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($stored);

            throw $exception;
        }

        $product->refresh();

        Log::info('Multimedia de producto agregada.', ['product_id' => $product->id, 'files' => $created->count()]);

        return $created;
    }

    /**
     * @param  list<string>  $stored  Rutas ya escritas, para borrarlas si algo falla después.
     *
     * @throws ValidationException
     */
    private function storeOne(Product $product, UploadedFile $file, int $position, array &$stored): ProductMedia
    {
        $disk = Storage::disk(self::DISK);
        $mime = (string) $file->getMimeType();
        $isVideo = ProductMediaFile::kindOf($file) === ProductMedia::TYPE_VIDEO;
        $thumbnail = null;
        $duration = null;

        if ($isVideo) {
            $name = Str::random(40).'.'.ProductMediaFile::VIDEO_EXTENSIONS[$mime];
            $disk->putFileAs(self::DIRECTORY, $file, $name);
            $stored[] = self::DIRECTORY.'/'.$name;
            $duration = (int) ceil((float) app(ProbeVideo::class)->durationSeconds($file->getRealPath()));
            $size = (int) $file->getSize();
        } else {
            [$main, $small] = $this->processImage($file);
            $name = Str::random(40).'.webp';
            $thumbnail = Str::random(40).'.webp';
            $disk->put(self::DIRECTORY.'/'.$name, $main);
            $stored[] = self::DIRECTORY.'/'.$name;
            $disk->put(self::DIRECTORY.'/'.$thumbnail, $small);
            $stored[] = self::DIRECTORY.'/'.$thumbnail;
            $mime = 'image/webp';
            $size = strlen($main);
        }

        return $product->media()->create([
            'type' => $isVideo ? ProductMedia::TYPE_VIDEO : ProductMedia::TYPE_IMAGE,
            'path' => $name,
            'thumbnail_path' => $thumbnail,
            'original_name' => Str::limit($file->getClientOriginalName(), 150, ''),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'duration_seconds' => $duration,
            'position' => $position,
        ]);
    }

    /**
     * @return array{0: string, 1: string} La foto reducida y su miniatura cuadrada, ambas en WebP.
     *
     * @throws ValidationException
     */
    private function processImage(UploadedFile $file): array
    {
        $side = (int) config('catalog.media.image_max_side');
        $thumbnailSide = (int) config('catalog.media.thumbnail_side');

        try {
            $image = ImageManager::usingDriver(Driver::class)
                ->decodePath($file->getRealPath())
                ->orient()
                ->scaleDown($side, $side);

            $main = (string) $image->encodeUsingFormat(Format::WEBP, quality: self::QUALITY);
            $small = (string) $image->cover($thumbnailSide, $thumbnailSide)->encodeUsingFormat(Format::WEBP, quality: self::QUALITY);
        } catch (ImageException) {
            throw ValidationException::withMessages(['media' => __('validation.media_image_unreadable')]);
        }

        return [$main, $small];
    }
}
