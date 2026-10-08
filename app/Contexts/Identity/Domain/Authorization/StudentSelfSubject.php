<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Authorization;

final readonly class StudentSelfSubject implements SchoolAuthorizationSubject
{
    /** @param list<int> $verifiedGuardianIdentityIds */
    public function __construct(
        public string $schoolId,
        public string $studentId,
        public int $studentIdentityId,
        public bool $published,
        public bool $guardianAccessAllowed,
        public array $verifiedGuardianIdentityIds = [],
    ) {}

    public function schoolPublicId(): string
    {
        return $this->schoolId;
    }
}
