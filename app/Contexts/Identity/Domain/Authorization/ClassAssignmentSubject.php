<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Authorization;

final readonly class ClassAssignmentSubject implements SchoolAuthorizationSubject
{
    public function __construct(
        public string $schoolId,
        public string $assignmentId,
        public int $assignedIdentityId,
        public string $status,
        public bool $visible,
        public bool $editable,
    ) {}

    public function schoolPublicId(): string
    {
        return $this->schoolId;
    }
}
