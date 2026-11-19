<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se declara en las rutas (App\Http\ResourceAuthorization)
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'classifications' => ['nullable', 'array', 'max:50'],
            'classifications.*' => ['integer'],
            'is_active' => ['nullable', 'boolean'],
            'stock_status' => ['nullable', Rule::in([Product::STOCK_OUT, Product::STOCK_LOW, Product::STOCK_OK])],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', Rule::when($this->filled('price_min'), 'gte:price_min')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
