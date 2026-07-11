<?php

namespace App\Rules;

use App\Services\PhoneService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Telefone implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PhoneService::validate($value)) {
            $fail('O telefone informado é inválido.');
        }
    }
}
