<?php

namespace App\Models;

use Database\Factories\ClassificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Classification extends Model
{
    /** @use HasFactory<ClassificationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'color_badge', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    /**
     * @param  Builder<Classification>  $query
     * @param  array{search?: string|null, is_active?: bool|int|string|null}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
            ))
            ->when(
                isset($filters['is_active']),
                fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active'])
            );
    }
}
