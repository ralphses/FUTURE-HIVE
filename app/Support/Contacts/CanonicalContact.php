<?php

declare(strict_types=1);

namespace App\Support\Contacts;

final readonly class CanonicalContact
{
    public function __construct(
        public string $type,
        public string $value,
    ) {}
}
