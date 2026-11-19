<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    public const string STOCK_OUT = 'out';

    public const string STOCK_LOW = 'low';

    public const string STOCK_OK = 'ok';

    /*
     * slug, created_by y updated_by no están a propósito: no se pueden asignar en masa desde un
     * formulario o una petición; los pone App\Actions\SaveProduct. El slug no cambia al renombrar
     * el producto porque forma parte de su dirección (y del código QR).
     */
    protected $fillable = [
        'sku', 'name', 'description', 'barcode', 'stock', 'min_stock',
        'purchase_price', 'sale_price', 'is_active',
    ];

    /**
     * Mismos valores que el default de la base de datos, para que un producto recién creado ya los tenga.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock' => 0,
        'min_stock' => 0,
        'purchase_price' => 0,
        'sale_price' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'min_stock' => 'integer',
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<Classification, $this>
     */
    public function classifications(): BelongsToMany
    {
        return $this->belongsToMany(Classification::class)->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Sin existencias (out), por debajo o igual al mínimo (low) o suficiente (ok).
     */
    public function stockStatus(): string
    {
        return match (true) {
            $this->stock === 0 => self::STOCK_OUT,
            $this->stock <= $this->min_stock => self::STOCK_LOW,
            default => self::STOCK_OK,
        };
    }

    public function formattedSalePrice(): string
    {
        return Number::currency((float) $this->sale_price, config('catalog.currency'), config('catalog.currency_locale'));
    }

    public function formattedPurchasePrice(): string
    {
        return Number::currency((float) $this->purchase_price, config('catalog.currency'), config('catalog.currency_locale'));
    }

    /**
     * Filtros del listado: search (nombre, SKU, código de barras o slug), classifications (ids; basta con
     * tener alguna), is_active, stock_status (out, low u ok) y price_min / price_max sobre el precio de venta.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
            ))
            ->when($filters['classifications'] ?? null, fn (Builder $query, array $ids) => $query->whereHas(
                'classifications',
                fn (Builder $query) => $query->whereIn('classifications.id', $ids)
            ))
            ->when(
                isset($filters['is_active']),
                fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active'])
            )
            ->when($filters['stock_status'] ?? null, fn (Builder $query, string $status) => match ($status) {
                self::STOCK_OUT => $query->where('stock', 0),
                self::STOCK_LOW => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'min_stock'),
                self::STOCK_OK => $query->where('stock', '>', 0)->whereColumn('stock', '>', 'min_stock'),
                default => $query,
            })
            ->when(isset($filters['price_min']), fn (Builder $query) => $query->where('sale_price', '>=', $filters['price_min']))
            ->when(isset($filters['price_max']), fn (Builder $query) => $query->where('sale_price', '<=', $filters['price_max']));
    }
}
