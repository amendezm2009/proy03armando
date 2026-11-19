<?php

namespace App\Http\Requests;

use App\Rules\Ean13;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se declara en las rutas (App\Http\ResourceAuthorization)
    }

    /**
     * El formulario envía un valor vacío junto a las casillas para poder quitar todas las
     * clasificaciones; aquí se descartan los vacíos y queda la lista real (posiblemente vacía).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('classification_ids') && is_array($this->input('classification_ids'))) {
            $this->merge([
                'classification_ids' => array_values(array_filter(
                    $this->input('classification_ids'),
                    fn (mixed $id): bool => $id !== null && $id !== '',
                )),
            ]);
        }
    }

    /**
     * El precio de venta debe ser mayor que el de compra. En una actualización parcial (PATCH) el precio que
     * no viene en la petición se toma del producto guardado, así cambiar solo uno de los dos también se valida.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['purchase_price', 'sale_price'])) {
                return;
            }

            $product = $this->route('product');
            $purchase = $this->input('purchase_price', $product?->purchase_price);
            $sale = $this->input('sale_price', $product?->sale_price);

            if ($purchase === null || $sale === null || (float) $sale > (float) $purchase) {
                return;
            }

            $validator->errors()->add(
                $this->has('sale_price') ? 'sale_price' : 'purchase_price',
                __('validation.sale_price_above_purchase'),
            );
        });
    }

    /**
     * En PATCH (actualización parcial) los campos solo se validan si vienen en la petición.
     * El SKU y el código de barras vacíos significan "generar" al crear y "no cambiar" al editar.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $product = $this->route('product');

        return [
            'name' => [$required, 'string', 'min:3', 'max:150'],
            'sku' => [
                'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('products', 'sku')->ignore($product),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'barcode' => ['nullable', new Ean13, Rule::unique('products', 'barcode')->ignore($product)],
            'stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:9999999'],
            'min_stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:9999999'],
            'purchase_price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'sale_price' => [$required, 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'classification_ids' => ['sometimes', 'array', 'max:50'],
            'classification_ids.*' => ['integer', 'distinct', Rule::exists('classifications', 'id')],
        ];
    }
}
