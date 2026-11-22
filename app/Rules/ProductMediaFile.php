<?php

namespace App\Rules;

use App\Actions\ProbeVideo;
use App\Models\ProductMedia;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Una foto (JPG, PNG o WebP) o un video (MP4 o WebM) de producto, según config('catalog.media'):
 * la foto pesa como máximo image_max_kb y no supera los megapíxeles permitidos; el video pesa MENOS de
 * video_max_bytes y dura como máximo video_max_seconds. El tipo se decide por el CONTENIDO del archivo
 * (no por su extensión ni por el tipo que declara el navegador).
 */
class ProductMediaFile implements ValidationRule
{
    /** @var list<string> */
    public const array IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Tipo de video admitido => extensión con la que se guarda. */
    public const array VIDEO_EXTENSIONS = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];

    /**
     * "image", "video" o null si el contenido no es de un tipo admitido.
     */
    public static function kindOf(UploadedFile $file): ?string
    {
        $mime = (string) $file->getMimeType();

        return match (true) {
            in_array($mime, self::IMAGE_MIMES, true) => ProductMedia::TYPE_IMAGE,
            isset(self::VIDEO_EXTENSIONS[$mime]) => ProductMedia::TYPE_VIDEO,
            default => null,
        };
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('validation.media_upload_failed')->translate();

            return;
        }

        match (self::kindOf($value)) {
            ProductMedia::TYPE_IMAGE => $this->validateImage($value, $fail),
            ProductMedia::TYPE_VIDEO => $this->validateVideo($value, $fail),
            default => $fail('validation.media_type')->translate(),
        };
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    private function validateImage(UploadedFile $file, Closure $fail): void
    {
        $maxKb = (int) config('catalog.media.image_max_kb');

        if ($file->getSize() > $maxKb * 1024) {
            $fail('validation.media_image_size')->translate(['max' => round($maxKb / 1024).' MB']);

            return;
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            $fail('validation.media_image_unreadable')->translate();

            return;
        }

        $maxMegapixels = (int) config('catalog.media.image_max_megapixels');

        if ($maxMegapixels * 1_000_000 < $dimensions[0] * $dimensions[1]) {
            $fail('validation.media_image_pixels')->translate(['max' => $maxMegapixels]);
        }
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    private function validateVideo(UploadedFile $file, Closure $fail): void
    {
        $maxBytes = (int) config('catalog.media.video_max_bytes');

        if ($file->getSize() >= $maxBytes) {
            $fail('validation.media_video_size')->translate(['max' => round($maxBytes / 1048576).' MB']);

            return;
        }

        $seconds = app(ProbeVideo::class)->durationSeconds($file->getRealPath());

        if ($seconds === null) {
            $fail('validation.media_video_unreadable')->translate();

            return;
        }

        $maxSeconds = (int) config('catalog.media.video_max_seconds');

        if ($seconds > $maxSeconds) {
            $fail('validation.media_video_duration')->translate(['max' => intdiv($maxSeconds, 60).' minutos']);
        }
    }
}
