<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Authorization;

final readonly class GuardianLinkSubject implements SchoolAuthorizationSubject
{
    public function __construct(
        public string $schoolId,
        public string $linkId,
        public int $guardianIdentityId,
        public int $studentIdentityId,
        public bool $verified,
        public bool $effective,
        public string $status,
    ) {}

    public function schoolPublicId(): string
    {
        return $this->schoolId;
    }
}
