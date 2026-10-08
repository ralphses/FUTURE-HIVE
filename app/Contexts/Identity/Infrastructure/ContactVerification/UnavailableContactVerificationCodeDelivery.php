<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Infrastructure\ContactVerification;

use App\Contexts\Identity\Application\Contracts\ContactVerificationCodeDelivery;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use RuntimeException;

final class UnavailableContactVerificationCodeDelivery implements ContactVerificationCodeDelivery
{
    public function send(UserIdentity $identity, UserContact $contact, string $code): void
    {
        throw new RuntimeException('Contact verification delivery is not configured.');
    }
}
