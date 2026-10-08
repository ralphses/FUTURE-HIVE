<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Authorization;

final readonly class FinancialScopeSubject implements SchoolAuthorizationSubject
{
    /**
     * @param  list<int>  $readIdentityIds
     * @param  list<int>  $manageIdentityIds
     */
    public function __construct(
        public string $schoolId,
        public string $accountId,
        public bool $active,
        public array $readIdentityIds = [],
        public array $manageIdentityIds = [],
    ) {}

    public function schoolPublicId(): string
    {
        return $this->schoolId;
    }
}
