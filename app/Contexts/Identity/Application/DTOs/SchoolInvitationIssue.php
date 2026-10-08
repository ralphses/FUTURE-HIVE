<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

use App\Contexts\Identity\Domain\Models\SchoolInvitation;

final readonly class SchoolInvitationIssue
{
    public function __construct(
        public SchoolInvitation $invitation,
        public string $token,
    ) {}
}
