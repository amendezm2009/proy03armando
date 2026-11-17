<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud de las acciones sensibles que solo necesitan la contraseña de quien las hace
 * (desactivar, reactivar, reenviar invitación). La contraseña se verifica después, con límite
 * de intentos, en App\Actions\ConfirmPassword.
 */
class PasswordConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se declara en las rutas (ResourceAuthorization y UserPolicy)
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
        ];
    }
}
