<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Exceptions;

use RuntimeException;

final class IdempotencyKeyReused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The idempotency key was already used for a different request.');
    }
}
