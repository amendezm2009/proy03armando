<?php

// archivo: app/Http/Requests/ClassificationRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se declara en las rutas (App\Http\ResourceAuthorization)
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(bool $partial = false, mixed $ignoreId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [
                $required, 'string', 'max:100',
                Rule::unique('classifications', 'name')->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'color_badge' => [$required, 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return static::baseRules($this->isMethod('PATCH'), $this->route('classification'));
    }
}
