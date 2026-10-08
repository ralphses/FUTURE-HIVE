<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Authorization;

interface SchoolAuthorizationSubject
{
    public function schoolPublicId(): string;
}
