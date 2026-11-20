<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tamaño de página de cada lista de los paneles. Cada lista usa su propio parámetro (attention_per_page,
 * products_per_page, users_per_page en el del administrador; per_page en el del cliente) para que cambiar el
 * tamaño de una no afecte a las demás. Los valores permitidos salen de config('catalog.page_sizes').
 */
class DashboardRequest extends FormRequest
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
        $rule = ['nullable', 'integer', Rule::in(config('catalog.page_sizes'))];

        return [
            'attention_per_page' => $rule,
            'products_per_page' => $rule,
            'users_per_page' => $rule,
            'per_page' => $rule,
        ];
    }

    /**
     * Tamaño de la lista que usa ese parámetro: el elegido o, si no hay, el de config('catalog.dashboard_page_size').
     */
    public function size(string $key): int
    {
        return (int) ($this->validated($key) ?? config('catalog.dashboard_page_size'));
    }
}
