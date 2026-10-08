<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\DTOs;

final readonly class CreateSchoolRegistrationData
{
    public function __construct(
        public string $schoolName,
        public string $schoolType,
        public string $state,
        public string $contact,
        public string $consentVersion,
        public ?string $requestId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
