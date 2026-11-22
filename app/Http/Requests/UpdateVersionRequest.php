<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud que solo nombra una versión (verificar un paquete subido).
 */
class UpdateVersionRequest extends FormRequest
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
        ];
    }
}
