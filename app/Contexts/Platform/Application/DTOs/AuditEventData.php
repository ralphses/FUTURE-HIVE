<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\DTOs;

final readonly class AuditEventData
{
    /**
     * @param  array<string, mixed>  $authorizationContext
     * @param  array<string, mixed>  $stateTransition
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $action,
        public string $subjectType,
        public ?string $subjectPublicId = null,
        public ?int $schoolId = null,
        public ?int $actorId = null,
        public ?string $reason = null,
        public ?string $requestId = null,
        public array $authorizationContext = [],
        public array $stateTransition = [],
        public array $metadata = [],
        public bool $requiresSchoolContext = false,
    ) {}
}
