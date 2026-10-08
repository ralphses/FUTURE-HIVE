<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

final readonly class SupersedeUserContactData
{
    public function __construct(
        public int $contactId,
        public string $reason,
        public ?int $replacementContactId = null,
    ) {}
}
