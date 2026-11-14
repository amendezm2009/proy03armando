<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait UserValidationRules
{
    /**
     * @return array<int, mixed>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, mixed>
     */
    protected function emailRules(?User $ignore = null): array
    {
        return ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($ignore)];
    }

    /**
     * @return array<int, mixed>
     */
    protected function passwordRules(): array
    {
        return ['required', 'confirmed', Password::defaults()];
    }
}
