<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Enums;

enum RegistrationContactType: string
{
    case Email = 'email';
    case Phone = 'phone';
}
