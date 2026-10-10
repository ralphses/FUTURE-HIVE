<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Infrastructure\Invitations;

use App\Contexts\Registry\Application\Contracts\GuardianInvitationCodeDelivery;
use App\Contexts\Registry\Domain\Models\GuardianInvitation;
use RuntimeException;

final class UnavailableGuardianInvitationCodeDelivery implements GuardianInvitationCodeDelivery
{
    public function send(GuardianInvitation $invitation, string $code): void
    {
        throw new RuntimeException('Guardian invitation delivery is unavailable.');
    }
}
