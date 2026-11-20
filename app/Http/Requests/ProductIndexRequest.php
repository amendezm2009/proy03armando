<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se declara en las rutas (App\Http\ResourceAuthorization)
    }

    /**
     * Filtros validados, con el estado por defecto según quién consulta: quien no puede editar productos (el cliente)
     * ve solo los activos mientras no elija otra cosa en el filtro "Estado" (Inactivos o Todos, que llega vacío).
     * Es una preferencia de vista, no un bloqueo: quien edita productos ve todos por defecto.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();

        if (! $this->has('is_active') && ! $this->user()->can(PermissionName::UpdateProducts->value)) {
            $filters['is_active'] = true;
        }

        return $filters;
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
