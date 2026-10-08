<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Contracts;

use App\Contexts\Platform\Domain\Models\SchoolRegistration;

interface RegistrationVerificationCodeDelivery
{
    public function send(SchoolRegistration $registration, string $code): void;
}
