<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\UserValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use UserValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // La contraseña actual se verifica, con límite de intentos, en App\Actions\ConfirmPassword.
            'current_password' => ['required', 'string'],
            'password' => $this->passwordRules(),
        ];
    }
}
