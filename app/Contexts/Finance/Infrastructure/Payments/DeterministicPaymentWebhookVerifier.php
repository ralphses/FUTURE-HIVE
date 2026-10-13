<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Infrastructure\Payments;

use App\Contexts\Finance\Application\Contracts\PaymentWebhookVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;

final class DeterministicPaymentWebhookVerifier implements PaymentWebhookVerifier
{
    public function verify(string $provider, string $rawPayload, ?string $signature): array
    {
        if ($provider !== 'deterministic' || $signature === null || ! str_starts_with($signature, 'sha256=')) {
            throw new AuthenticationException;
        }

        $secret = (string) config('services.payment_webhooks.providers.deterministic.secret');
        $expected = hash_hmac('sha256', $rawPayload, $secret);
        $provided = substr($signature, 7);
        if ($secret === '' || ! hash_equals($expected, $provided)) {
            throw new AuthenticationException;
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }

        $required = ['event_id', 'event_type', 'payment_intent_id', 'invoice_id', 'school_id', 'currency', 'amount_minor_units', 'occurred_at'];
        foreach ($required as $key) {
            if (! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
            }
        }

        $eventType = (string) $payload['event_type'];
        if (! in_array($eventType, ['payment.pending', 'payment.completed', 'payment.cancelled', 'payment.expired'], true)) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }

        try {
            $occurredAt = CarbonImmutable::parse((string) $payload['occurred_at']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }

        if (abs(now()->diffInSeconds($occurredAt, false)) > (int) config('services.payment_webhooks.tolerance_seconds', 300)) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }

        $amount = filter_var($payload['amount_minor_units'], FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 0 || ! preg_match('/^[A-Z]{3}$/', (string) $payload['currency'])) {
            throw ValidationException::withMessages(['webhook' => ['The webhook could not be verified.']]);
        }

        return [
            'event_id' => (string) $payload['event_id'],
            'event_type' => $eventType,
            'payment_intent_id' => (string) $payload['payment_intent_id'],
            'invoice_id' => (string) $payload['invoice_id'],
            'school_id' => (string) $payload['school_id'],
            'currency' => (string) $payload['currency'],
            'amount_minor_units' => $amount,
            'occurred_at' => $occurredAt->toAtomString(),
        ];
    }
}
