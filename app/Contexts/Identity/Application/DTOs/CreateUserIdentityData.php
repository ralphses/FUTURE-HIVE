<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

final readonly class CreateUserIdentityData
{
    /** @param list<ContactData> $contacts */
    public function __construct(
        public string $name,
        public array $contacts,
    ) {}
}
