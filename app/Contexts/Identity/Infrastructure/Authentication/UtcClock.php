<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Infrastructure\Authentication;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class UtcClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
