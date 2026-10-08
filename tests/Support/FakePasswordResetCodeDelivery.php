<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class FakePasswordResetCodeDelivery implements PasswordResetCodeDelivery
{
    public string $code = '';

    public ?string $contact = null;

    public function send(UserIdentity $identity, UserContact $contact, string $code): void
    {
        $this->code = $code;
        $this->contact = $contact->canonical_value;
    }
}
