<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Contracts;

use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;

interface PasswordResetCodeDelivery
{
    public function send(UserIdentity $identity, UserContact $contact, string $code): void;
}
