<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contexts\Registry\Application\Contracts\GuardianInvitationCodeDelivery;
use App\Contexts\Registry\Domain\Models\GuardianInvitation;
use RuntimeException;

final class FakeGuardianInvitationCodeDelivery implements GuardianInvitationCodeDelivery
{
    public string $code = '';

    public ?string $invitationId = null;

    public bool $shouldFail = false;

    public function send(GuardianInvitation $invitation, string $code): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Delivery failed in test.');
        }

        $this->code = $code;
        $this->invitationId = $invitation->public_id;
    }
}
