<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

final readonly class AuthorizationDecision
{
    public function __construct(
        public bool $allowed,
        public string $code,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'ALLOWED');
    }

    public static function deny(string $code): self
    {
        return new self(false, $code);
    }
}
