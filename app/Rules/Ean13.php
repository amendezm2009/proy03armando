<?php

namespace App\Rules;

use App\Actions\GenerateBarcode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * El valor debe ser un EAN-13: 13 dígitos con el dígito de control correcto.
 */
class Ean13 implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! GenerateBarcode::isValid($value)) {
            $fail('validation.ean13')->translate();
        }
    }
}
