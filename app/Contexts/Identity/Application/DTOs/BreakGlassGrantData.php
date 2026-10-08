<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

use DateTimeInterface;

final readonly class BreakGlassGrantData
{
    /**
     * @param  array<string, mixed>  $approvalContext
     */
    public function __construct(
        public int $targetUserId,
        public int $schoolId,
        public string $resourceScope,
        public string $purpose,
        public array $approvalContext,
        public DateTimeInterface $expiresAt,
        public ?string $requestId = null,
    ) {}
}
