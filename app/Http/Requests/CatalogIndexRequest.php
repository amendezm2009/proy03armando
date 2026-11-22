<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // catálogo público: no requiere sesión
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'classification' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', Rule::in(config('catalog.page_sizes'))],
        ];
    }
}
