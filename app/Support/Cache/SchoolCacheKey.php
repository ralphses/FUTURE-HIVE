<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

final class SchoolCacheKey
{
    public static function make(string $schoolId, string $key): string
    {
        $context = TenantContext::require();
        $schoolId = trim($schoolId);

        if ($schoolId !== $context->schoolPublicId) {
            throw new InvalidArgumentException('The cache school does not match the trusted tenant context.');
        }

        return self::forContext($context, $key);
    }

    public static function forContext(TenantContext $context, string $key): string
    {
        $key = trim($key);

        if ($key === '' || str_contains($key, '..') || str_contains($key, '\\') || str_starts_with($key, '/')) {
            throw new InvalidArgumentException('A safe cache key suffix is required.');
        }

        return sprintf('school:%s:%s', $context->schoolPublicId, $key);
    }
}
