<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una parte de un paquete de actualización (se suben de una en una por el límite de tamaño del hosting). El nombre
 * del archivo lo valida App\Actions\StoreUpdatePart: aquí solo que venga la versión y un archivo del tamaño permitido.
 */
class UpdateUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se declara en las rutas (ResourceAuthorization)
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'file' => ['required', 'file', 'max:'.(int) config('release.upload_max_kb')],
        ];
    }
}
