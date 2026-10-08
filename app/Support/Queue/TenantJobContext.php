<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Contexts\Identity\Domain\Models\School;
use InvalidArgumentException;

final readonly class TenantJobContext
{
    public function __construct(
        public int $schoolId,
        public string $schoolPublicId,
    ) {
        if ($schoolId < 1 || trim($schoolPublicId) === '') {
            throw new InvalidArgumentException('A valid school context is required.');
        }
    }

    public static function fromSchool(School $school): self
    {
        return new self((int) $school->id, (string) $school->public_id);
    }
}
