<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\UserValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class ProfileRequest extends FormRequest
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
            'name' => $this->nameRules(),
            'email' => $this->emailRules($this->user()),
        ];
    }
}
