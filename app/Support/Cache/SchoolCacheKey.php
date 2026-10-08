<?php

declare(strict_types=1);

namespace App\Support\Cache;

use InvalidArgumentException;

final class SchoolCacheKey
{
    public static function make(string $schoolId, string $key): string
    {
        $schoolId = trim($schoolId);
        $key = trim($key);

        if ($schoolId === '') {
            throw new InvalidArgumentException('A school_id is required for school-scoped cache keys.');
        }

        if ($key === '') {
            throw new InvalidArgumentException('A cache key suffix is required.');
        }

        return sprintf('school:%s:%s', $schoolId, $key);
    }
}
