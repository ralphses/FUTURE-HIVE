<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Exceptions;

use Illuminate\Validation\ValidationException;

final class PromotionCycleInvalid
{
    public static function make(string $message): ValidationException
    {
        return ValidationException::withMessages(['promotion' => [$message]]);
    }
}
