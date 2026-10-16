<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Infrastructure\Payments;

use App\Contexts\Platform\Application\Contracts\PlatformBillingWebhookVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;

final class DeterministicPlatformBillingWebhookVerifier implements PlatformBillingWebhookVerifier
{
    public function verify(string $provider, string $rawPayload, ?string $signature): array
    {
        if ($provider !== 'deterministic' || $signature === null || ! str_starts_with($signature, 'sha256=')) {
            throw new AuthenticationException;
        }
        $secret = (string) config('services.payment_webhooks.providers.deterministic.secret');
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $rawPayload, $secret), substr($signature, 7))) {
            throw new AuthenticationException;
        }
        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
            $occurredAt = CarbonImmutable::parse((string) ($payload['occurred_at'] ?? ''));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }
        $required = ['event_id', 'event_type', 'payment_id', 'invoice_id', 'school_id', 'currency', 'amount_minor_units'];
        foreach ($required as $key) {
            if (! is_array($payload) || ! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
            }
        }
        $amount = filter_var($payload['amount_minor_units'], FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 1 || ! in_array($payload['event_type'], ['payment.completed', 'payment.failed', 'payment.cancelled', 'payment.refunded'], true) || ! preg_match('/^[A-Z]{3}$/', (string) $payload['currency']) || abs(now()->diffInSeconds($occurredAt, false)) > (int) config('services.payment_webhooks.tolerance_seconds', 300)) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }

        return ['event_id' => (string) $payload['event_id'], 'event_type' => (string) $payload['event_type'], 'payment_id' => (string) $payload['payment_id'], 'invoice_id' => (string) $payload['invoice_id'], 'school_id' => (string) $payload['school_id'], 'currency' => (string) $payload['currency'], 'amount_minor_units' => $amount, 'occurred_at' => $occurredAt->toAtomString()];
    }
}
