<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

final readonly class SchoolLifecycleContext
{
    public function __construct(
        public int $schoolId,
        public string $schoolPublicId,
        public int $membershipId,
        public int $identityId,
        public string $schoolStatus,
    ) {}
}
