<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

use App\Contexts\Identity\Domain\Models\SchoolMembership;

final readonly class SchoolContext
{
    public function __construct(public SchoolMembership $membership) {}

    /** @return array{school_id: string, school_name: string, membership_id: string, is_owner: bool} */
    public function toArray(): array
    {
        return [
            'school_id' => (string) $this->membership->school->public_id,
            'school_name' => (string) $this->membership->school->name,
            'membership_id' => (string) $this->membership->public_id,
            'is_owner' => (bool) $this->membership->is_owner,
        ];
    }
}
