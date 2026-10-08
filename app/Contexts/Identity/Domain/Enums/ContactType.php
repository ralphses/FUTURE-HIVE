<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Enums;

enum ContactType: string
{
    case Email = 'email';
    case Phone = 'phone';
}
