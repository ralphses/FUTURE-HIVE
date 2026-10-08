<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Infrastructure\PasswordReset;

use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use RuntimeException;

final class UnavailablePasswordResetCodeDelivery implements PasswordResetCodeDelivery
{
    public function send(UserIdentity $identity, UserContact $contact, string $code): void
    {
        throw new RuntimeException('Password reset delivery is not configured.');
    }
}
