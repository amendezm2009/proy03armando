<?php

namespace App\Models;

use Database\Factories\ProductMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMedia extends Model
{
    /** @use HasFactory<ProductMediaFactory> */
    use HasFactory;

    public const string TYPE_IMAGE = 'image';

    public const string TYPE_VIDEO = 'video';

    protected $table = 'product_media';

    protected $fillable = [
        'type', 'path', 'thumbnail_path', 'original_name', 'mime_type', 'size_bytes', 'duration_seconds', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'duration_seconds' => 'integer', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    /**
     * Dirección pública del archivo (la sirve ProductMediaFileController).
     */
    public function url(): string
    {
        return route('products.media.file', $this->path);
    }

    /**
     * Miniatura cuadrada de una foto; los videos no tienen.
     */
    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path !== null ? route('products.media.file', $this->thumbnail_path) : null;
    }

    /**
     * Duración de un video como m:ss, o null si es una foto.
     */
    public function durationLabel(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }
}
