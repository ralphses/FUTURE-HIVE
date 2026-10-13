<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Application\Contracts\PaymentWebhookVerifier;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentIntent;
use App\Contexts\Finance\Domain\Models\FeePaymentWebhookEvent;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProcessPaymentWebhookAction
{
    public function __construct(
        private readonly PaymentWebhookVerifier $verifier,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{webhook: array<string, mixed>} */
    public function execute(string $provider, string $rawPayload, ?string $signature): array
    {
        $event = $this->verifier->verify($provider, $rawPayload, $signature);
        $fingerprint = hash_hmac('sha256', $rawPayload, (string) config('app.key'));
        $eventHash = hash_hmac('sha256', $provider.'|'.$event['event_id'], (string) config('app.key'));

        return DB::transaction(function () use ($event, $eventHash, $fingerprint, $provider): array {
            $existing = FeePaymentWebhookEvent::query()
                ->where('provider', $provider)
                ->where('event_id_hash', $eventHash)
                ->with('intent')
                ->lockForUpdate()
                ->first();

            if ($existing instanceof FeePaymentWebhookEvent) {
                if ($existing->payload_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException;
                }

                $existingIntent = FeePaymentIntent::query()
                    ->withoutGlobalScope('trusted_tenant')
                    ->find($existing->fee_payment_intent_id);

                $this->recordAudit($existingIntent, 'finance.payment_webhook_duplicate', ['status' => 'duplicate', 'provider' => $provider]);

                return ['webhook' => [
                    'provider' => $provider,
                    'status' => 'duplicate',
                    'payment_intent_id' => (string) $existingIntent?->public_id,
                    'intent_status' => (string) $existingIntent?->status,
                ]];
            }

            $intent = FeePaymentIntent::query()
                ->withoutGlobalScope('trusted_tenant')
                ->where('public_id', $event['payment_intent_id'])
                ->lockForUpdate()
                ->first();

            $invoice = $intent instanceof FeePaymentIntent
                ? FeeInvoice::query()->withoutGlobalScope('trusted_tenant')->find($intent->fee_invoice_id)
                : null;
            $school = $intent instanceof FeePaymentIntent ? School::query()->find($intent->school_id) : null;
            if (! $intent instanceof FeePaymentIntent || ! $invoice || ! $school || (string) $invoice->public_id !== $event['invoice_id'] || (string) $school->public_id !== $event['school_id'] || (string) $intent->currency !== $event['currency'] || (int) $intent->amount_minor_units !== $event['amount_minor_units']) {
                throw new ModelNotFoundException;
            }

            if ((string) $invoice->status !== 'issued') {
                throw new ModelNotFoundException;
            }

            $target = match ($event['event_type']) {
                'payment.pending' => 'pending',
                'payment.completed' => 'completed',
                'payment.cancelled' => 'cancelled',
                'payment.expired' => 'expired',
                default => throw new ConflictHttpException,
            };
            $current = (string) $intent->status;
            if ($current === $target) {
                return $this->storeEvent($intent, $provider, $eventHash, $fingerprint, $event, 'duplicate', $current);
            }
            if (! in_array($current, ['created', 'pending'], true)) {
                throw new ConflictHttpException;
            }

            $intent->update(['status' => $target, 'expires_at' => $target === 'expired' ? now() : $intent->expires_at]);

            return $this->storeEvent($intent, $provider, $eventHash, $fingerprint, $event, 'accepted', $current, $target);
        });
    }

    /**
     * @param  array{event_id: string, event_type: string, payment_intent_id: string, invoice_id: string, school_id: string, currency: string, amount_minor_units: int, occurred_at: string}  $event
     * @return array{webhook: array<string, mixed>}
     */
    private function storeEvent(FeePaymentIntent $intent, string $provider, string $eventHash, string $fingerprint, array $event, string $status, string $from, ?string $to = null): array
    {
        $stored = FeePaymentWebhookEvent::query()->create([
            'school_id' => $intent->school_id,
            'fee_payment_intent_id' => $intent->getKey(),
            'provider' => $provider,
            'event_id_hash' => $eventHash,
            'payload_fingerprint' => $fingerprint,
            'event_type' => $event['event_type'],
            'status' => $status,
            'request_id' => Context::get('request_id'),
            'observed_at' => CarbonImmutable::parse($event['occurred_at']),
        ]);
        $this->recordAudit($intent, 'finance.payment_webhook_'.$status, ['from' => $from, 'to' => $to, 'provider' => $provider]);

        return ['webhook' => [
            'provider' => $provider,
            'status' => $status,
            'payment_intent_id' => (string) $intent->public_id,
            'intent_status' => (string) $intent->status,
            'event_record_id' => (string) $stored->public_id,
        ]];
    }

    /** @param array<string, string|null> $transition */
    private function recordAudit(?FeePaymentIntent $intent, string $action, array $transition): void
    {
        $this->audit->execute(new AuditEventData(
            action: $action,
            subjectType: 'fee_payment_intent',
            subjectPublicId: $intent?->public_id,
            schoolId: $intent?->school_id,
            stateTransition: $transition,
            metadata: ['request_id' => Context::get('request_id')],
        ));
    }
}
