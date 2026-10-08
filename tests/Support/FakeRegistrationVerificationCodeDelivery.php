<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contexts\Platform\Application\Contracts\RegistrationVerificationCodeDelivery;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use RuntimeException;

final class FakeRegistrationVerificationCodeDelivery implements RegistrationVerificationCodeDelivery
{
    public string $code = '';

    public ?string $registrationId = null;

    public ?string $contact = null;

    public bool $shouldFail = false;

    public function send(SchoolRegistration $registration, string $code): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Delivery failed in test.');
        }

        $this->code = $code;
        $this->registrationId = $registration->public_id;
        $this->contact = $registration->canonical_contact;
    }
}
