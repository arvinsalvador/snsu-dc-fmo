<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** The single password policy used by registration, self-service change, and administrator reset. */
class PasswordPolicy
{
    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return ['required', 'string', 'confirmed', Password::min(12)];
    }
}
