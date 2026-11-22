<?php

namespace App\Http\Requests;

/**
 * Empezar a instalar una versión: la versión y la contraseña de quien lo hace (se verifica con
 * App\Actions\ConfirmPassword, con límite de intentos).
 */
class UpdateStartRequest extends PasswordConfirmationRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
        ];
    }
}
