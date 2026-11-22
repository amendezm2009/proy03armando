<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\UserValidationRules;
use App\Models\User;
use App\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de usuarios (web y API). Solo acepta name, email y role: la contraseña no se
 * envía (la define la persona con el enlace del correo) y is_active se cambia únicamente con
 * las rutas de activar y desactivar. PATCH acepta cambios parciales.
 */
class UserRequest extends FormRequest
{
    use UserValidationRules;

    public function authorize(): bool
    {
        return true; // la autorización se declara en las rutas (App\Http\ResourceAuthorization)
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $target = $target instanceof User ? $target : null;

        return [
            'name' => $this->partial($this->nameRules()),
            'email' => $this->partial($this->emailRules($target)),
            'role' => $this->partial(['required', 'string', Rule::enum(RoleName::class)]),
            ...($this->requiresPasswordConfirmation() ? ['current_password' => ['required', 'string']] : []),
        ];
    }

    /**
     * ¿Hay que pedir la contraseña de quien hace el cambio? Siempre al crear; al editar, solo si
     * cambia el rol (privilegios) o el correo (con él se recupera la cuenta). Cambiar solo el nombre
     * no la pide. La verifica App\Actions\ConfirmPassword en el controlador, con límite de intentos.
     */
    public function requiresPasswordConfirmation(): bool
    {
        $target = $this->route('user');

        if (! $target instanceof User) {
            return true;
        }

        return ($this->has('role') && $this->input('role') !== $target->getRoleNames()->first())
            || ($this->has('email') && $this->input('email') !== $target->email);
    }

    /**
     * Reglas de quién puede cambiar el rol de quién.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $target = $this->route('user');

            if (! $target instanceof User || ! $this->filled('role') || $this->input('role') === $target->getRoleNames()->first()) {
                return;
            }

            if ($this->user()->is($target)) {
                $validator->errors()->add('role', 'No puedes cambiar tu propio rol.');
            } elseif ($target->isLastActiveAdmin() && $this->input('role') !== RoleName::Admin->value) {
                $validator->errors()->add('role', 'No se puede quitar el rol a la única persona administradora activa.');
            }
        }];
    }

    /**
     * En PATCH, "required" pasa a "sometimes": solo se valida lo que se envía.
     *
     * @param  array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    private function partial(array $rules): array
    {
        return $this->isMethod('PATCH') ? ['sometimes', ...array_slice($rules, 1)] : $rules;
    }
}
