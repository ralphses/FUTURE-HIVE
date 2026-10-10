<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Contracts;

use App\Contexts\Registry\Domain\Models\GuardianInvitation;

interface GuardianInvitationCodeDelivery
{
    public function send(GuardianInvitation $invitation, string $code): void;
}
