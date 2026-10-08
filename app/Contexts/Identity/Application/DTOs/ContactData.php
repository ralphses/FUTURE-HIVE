<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

use App\Contexts\Identity\Domain\Enums\ContactType;

final readonly class ContactData
{
    public function __construct(
        public ContactType $type,
        public string $value,
        public bool $isPrimary = false,
    ) {}
}
