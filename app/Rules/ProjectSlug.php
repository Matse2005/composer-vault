<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ProjectSlug implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * A slug starts with a lowercase letter, then holds lowercase letters, digits and single hyphens,
     * has 2 to 64 characters and does not end with a hyphen.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isValid = is_string($value)
            && preg_match('/^[a-z][a-z0-9-]{1,63}\z/', $value) === 1
            && ! str_ends_with($value, '-')
            && ! str_contains($value, '--');

        if (! $isValid) {
            $fail('The :attribute must start with a lowercase letter and contain only lowercase letters, digits and single hyphens, without a trailing hyphen.');
        }
    }
}
