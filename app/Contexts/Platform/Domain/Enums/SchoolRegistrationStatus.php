<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Enums;

enum SchoolRegistrationStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Provisioning = 'provisioning';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
