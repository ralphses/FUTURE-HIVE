<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Services;

use Illuminate\Validation\ValidationException;

final class PasswordPolicy
{
    public function validate(string $password): void
    {
        $length = mb_strlen($password);
        $commonPasswords = config('auth.password_recovery.common_passwords', []);

        if ($length < 12 || $length > 128) {
            throw ValidationException::withMessages([
                'password' => 'The password must be between 12 and 128 characters.',
            ]);
        }

        if (is_array($commonPasswords) && in_array(mb_strtolower($password), $commonPasswords, true)) {
            throw ValidationException::withMessages([
                'password' => 'The password is too common.',
            ]);
        }
    }
}
