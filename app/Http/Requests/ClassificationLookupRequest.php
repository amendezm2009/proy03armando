<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClassificationLookupRequest extends FormRequest
{
    /** Letras mínimas para buscar; con menos solo se ofrecen las recientes (búsqueda vacía). */
    public const int MIN_CHARS = 3;

    public function authorize(): bool
    {
        return true; // la autorización se declara en la ruta (App\Http\ResourceAuthorization)
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'only_active' => ['nullable', 'boolean'],
        ];
    }
}
