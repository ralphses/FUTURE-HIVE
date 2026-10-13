<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Application\Contracts\PaymentIntentGateway;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentIntent;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Exceptions\IdempotencyKeyReused;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FeePaymentIntentAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit, private readonly PaymentIntentGateway $gateway) {}

    /** @return array{payment_intents: list<array<string, mixed>>} */
    public function list(UserIdentity $actor, string $school, string $invoiceId): array
    {
        $this->authorize($actor, $school, 'finance.payment-intents.read');
        $invoice = $this->invoice($invoiceId);

        return ['payment_intents' => FeePaymentIntent::query()->where('fee_invoice_id', $invoice->getKey())->with('invoice')->orderBy('id')->get()->map(fn (FeePaymentIntent $intent): array => $this->data($intent))->all()];
    }

    /** @param array{currency: string, amount_minor_units: int, payer_reference?: string|null} $input
     * @return array{payment_intent: array<string, mixed>}
     */
    public function create(UserIdentity $actor, string $school, string $invoiceId, array $input, string $idempotencyKey): array
    {
        $this->authorize($actor, $school, 'finance.payment-intents.manage');
        if (! preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => ['The Idempotency-Key header must be 16 to 128 safe characters.']]);
        }
        $context = TenantContext::require();
        $keyHash = hash_hmac('sha256', 'finance-payment-intent|'.$context->schoolId.'|'.$idempotencyKey, (string) config('app.key'));
        $fingerprint = hash_hmac('sha256', json_encode(['invoice' => $invoiceId, 'currency' => strtoupper((string) $input['currency']), 'amount' => (int) $input['amount_minor_units'], 'payer_reference' => $input['payer_reference'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $context, $invoiceId, $input, $keyHash, $fingerprint): array {
            $existing = FeePaymentIntent::query()->where('school_id', $context->schoolId)->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing instanceof FeePaymentIntent) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new IdempotencyKeyReused;
                }

                return ['payment_intent' => $this->data($existing->load('invoice')) + $this->gateway->prepare($existing)];
            }
            $invoice = $this->invoice($invoiceId, true);
            if ($invoice->status !== 'issued') {
                throw new ModelNotFoundException;
            }
            $currency = strtoupper((string) $input['currency']);
            if ($currency !== (string) $invoice->currency) {
                throw ValidationException::withMessages(['currency' => ['The payment currency must match the invoice currency.']]);
            }
            $amount = (int) $input['amount_minor_units'];
            $allocated = (int) FeePaymentAllocation::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->sum('amount_minor_units');
            $activeIntents = (int) FeePaymentIntent::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->whereIn('status', ['created', 'pending'])->sum('amount_minor_units');
            if ($allocated + $activeIntents + $amount > (int) $invoice->total_minor_units) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The payment intent exceeds the outstanding invoice balance.']]);
            }
            $intent = FeePaymentIntent::query()->create(['school_id' => $context->schoolId, 'fee_invoice_id' => $invoice->getKey(), 'currency' => $currency, 'amount_minor_units' => $amount, 'status' => 'created', 'idempotency_key_hash' => $keyHash, 'request_fingerprint' => $fingerprint, 'payer_reference_hash' => isset($input['payer_reference']) ? hash_hmac('sha256', (string) $input['payer_reference'], (string) config('app.key')) : null, 'expires_at' => now()->addMinutes(30), 'created_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $this->record($actor, 'finance.payment_intent_created', 'fee_payment_intent', (string) $intent->public_id, ['to' => 'created']);

            return ['payment_intent' => $this->data($intent->load('invoice')) + $this->gateway->prepare($intent)];
        });
    }

    /** @return array{payment_intent: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $invoiceId, string $intentId): array
    {
        $this->authorize($actor, $school, 'finance.payment-intents.read');
        $invoice = $this->invoice($invoiceId);
        $intent = FeePaymentIntent::query()->where('fee_invoice_id', $invoice->getKey())->where('public_id', $intentId)->with('invoice')->firstOrFail();

        return ['payment_intent' => $this->data($intent)];
    }

    /** @return array{payment_intent: array<string, mixed>} */
    public function cancel(UserIdentity $actor, string $school, string $invoiceId, string $intentId): array
    {
        $this->authorize($actor, $school, 'finance.payment-intents.manage');

        return DB::transaction(function () use ($actor, $invoiceId, $intentId): array {
            $intent = FeePaymentIntent::query()->where('public_id', $intentId)->with('invoice')->lockForUpdate()->firstOrFail();
            if ($intent->invoice?->public_id !== $invoiceId) {
                throw new ModelNotFoundException;
            }
            if ($intent->status === 'cancelled') {
                return ['payment_intent' => $this->data($intent)];
            }
            if (in_array($intent->status, ['completed', 'expired'], true)) {
                throw new ModelNotFoundException;
            }
            $intent->update(['status' => 'cancelled', 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()]);
            $this->record($actor, 'finance.payment_intent_cancelled', 'fee_payment_intent', (string) $intent->public_id, ['from' => (string) $intent->getOriginal('status'), 'to' => 'cancelled']);

            return ['payment_intent' => $this->data($intent->fresh('invoice'))];
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function invoice(string $invoiceId, bool $lock = false): FeeInvoice
    {
        $query = FeeInvoice::query()->where('public_id', $invoiceId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function data(FeePaymentIntent $intent): array
    {
        return ['id' => (string) $intent->public_id, 'invoice_id' => (string) $intent->invoice?->public_id, 'currency' => (string) $intent->currency, 'amount_minor_units' => (int) $intent->amount_minor_units, 'status' => (string) $intent->status, 'expires_at' => $this->timestamp($intent->expires_at), 'cancelled_at' => $this->timestamp($intent->cancelled_at), 'created_at' => $this->timestamp($intent->created_at)];
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface ? $value->toAtomString() : CarbonImmutable::parse((string) $value)->toAtomString();
    }

    /** @param array<string, mixed> $transition */
    private function record(UserIdentity $actor, string $action, string $subjectType, string $subjectId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: $subjectType, subjectPublicId: $subjectId, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
