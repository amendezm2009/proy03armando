<?php

namespace App\Models;

use Database\Factories\ClassificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
