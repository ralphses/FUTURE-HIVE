<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Infrastructure\Verification;

use App\Contexts\Platform\Application\Contracts\RegistrationVerificationCodeDelivery;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use RuntimeException;

final class UnavailableRegistrationVerificationCodeDelivery implements RegistrationVerificationCodeDelivery
{
    public function send(SchoolRegistration $registration, string $code): void
    {
        throw new RuntimeException('Registration verification delivery is not configured.');
    }
}
