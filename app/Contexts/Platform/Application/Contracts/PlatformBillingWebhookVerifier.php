<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Contracts;

interface PlatformBillingWebhookVerifier
{
    /** @return array{event_id: string, event_type: string, payment_id: string, invoice_id: string, school_id: string, currency: string, amount_minor_units: int, occurred_at: string} */
    public function verify(string $provider, string $rawPayload, ?string $signature): array;
}
