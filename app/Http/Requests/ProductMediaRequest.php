<?php

namespace App\Http\Requests;

use App\Rules\ProductMediaFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class ProductMediaRequest extends FormRequest
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
            'media' => ['required', 'array', 'min:1', 'max:'.config('catalog.media.max_files')],
            'media.*' => ['required', new ProductMediaFile],
        ];
    }

    /**
     * Los archivos nuevos más los que el producto ya tiene no pueden pasar del límite (AddProductMedia lo
     * vuelve a comprobar bloqueando el producto, por si dos subidas llegan a la vez).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('media')) {
                return;
            }

            $max = (int) config('catalog.media.max_files');

            if ($this->route('product')->media()->count() + count(Arr::wrap($this->file('media'))) > $max) {
                $validator->errors()->add('media', __('validation.media_max_files', ['max' => $max]));
            }
        });
    }
}
