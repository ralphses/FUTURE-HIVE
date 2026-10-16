<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\PlatformPermissionResolver;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\PlatformBillingInvoice;
use App\Contexts\Platform\Domain\Models\PlatformBillingPayment;
use App\Contexts\Platform\Domain\Models\PlatformBillingWebhookEvent;
use App\Contexts\Platform\Domain\Models\PlatformSubscription;
use App\Contexts\Platform\Domain\Models\PlatformSubscriptionPlan;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PlatformBillingAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $schoolPermissions,
        private readonly PlatformPermissionResolver $platformPermissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function invoices(Authenticatable $actor, ?string $school, array $filters): LengthAwarePaginator
    {
        $schoolId = $this->authorize($actor, $school, 'platform.billing.read');
        $query = PlatformBillingInvoice::query()->withoutGlobalScope('trusted_tenant')->where('school_id', $schoolId)->orderByDesc('created_at')->orderByDesc('id');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate(ApiResponse::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null))->through(fn (PlatformBillingInvoice $invoice): array => $this->invoiceData($invoice));
    }

    /** @return array{invoice: array<string, mixed>} */
    public function invoice(Authenticatable $actor, string $school, string $invoiceId): array
    {
        $schoolId = $this->authorize($actor, $school, 'platform.billing.read');

        return ['invoice' => $this->invoiceData($this->invoiceRecord($invoiceId, $schoolId))];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{invoice: array<string, mixed>}
     */
    public function createInvoice(Authenticatable $actor, string $school, array $input): array
    {
        $schoolId = $this->authorize($actor, $school, 'platform.billing.manage', true);
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') {
            throw new ConflictHttpException;
        }
        $fingerprint = hash('sha256', json_encode([$schoolId, $input['amount_minor_units'], $input['currency'], $input['due_at'] ?? null], JSON_THROW_ON_ERROR));
        $keyHash = hash_hmac('sha256', 'platform-billing-invoice|'.$schoolId.'|'.$key, (string) config('app.key'));

        $invoice = DB::transaction(function () use ($actor, $schoolId, $input, $fingerprint, $keyHash): PlatformBillingInvoice {
            $existing = PlatformBillingInvoice::query()->withoutGlobalScope('trusted_tenant')->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing instanceof PlatformBillingInvoice) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException;
                }

                return $existing;
            }

            $subscription = PlatformSubscription::query()->withoutGlobalScope('trusted_tenant')->where('school_id', $schoolId)->orderByDesc('created_at')->lockForUpdate()->first();
            $plan = $subscription instanceof PlatformSubscription ? PlatformSubscriptionPlan::query()->find($subscription->platform_subscription_plan_id) : null;
            if (! $subscription instanceof PlatformSubscription || ! $plan instanceof PlatformSubscriptionPlan || ! in_array($subscription->status, ['trial', 'active', 'grace'], true) || $plan->status !== 'active' || (int) $input['amount_minor_units'] <= 0) {
                throw new ModelNotFoundException;
            }

            $sequence = DB::table('platform_billing_sequences')->where('school_id', $schoolId)->lockForUpdate()->first();
            if ($sequence === null) {
                DB::table('platform_billing_sequences')->insert(['school_id' => $schoolId, 'next_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
                $number = 1;
            } else {
                $number = (int) $sequence->next_number;
                DB::table('platform_billing_sequences')->where('school_id', $schoolId)->update(['next_number' => $number + 1, 'updated_at' => now()]);
            }

            $invoice = PlatformBillingInvoice::query()->withoutGlobalScope('trusted_tenant')->create([
                'school_id' => $schoolId,
                'platform_subscription_id' => $subscription->getKey(),
                'platform_subscription_plan_id' => $plan->getKey(),
                'invoice_number' => 'SAA-'.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
                'plan_code_snapshot' => $plan->code,
                'plan_name_snapshot' => $plan->name,
                'plan_version_snapshot' => $plan->version,
                'amount_minor_units' => (int) $input['amount_minor_units'],
                'currency' => $input['currency'],
                'status' => 'draft',
                'due_at' => $input['due_at'] ?? null,
                'idempotency_key_hash' => $keyHash,
                'request_fingerprint' => $fingerprint,
                'created_by' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, 'platform.billing.invoice.created', $invoice, ['to' => 'draft']);

            return $invoice;
        });

        return ['invoice' => $this->invoiceData($invoice)];
    }

    /** @return array{invoice: array<string, mixed>} */
    public function transitionInvoice(Authenticatable $actor, string $school, string $invoiceId, string $transition, ?string $reason = null): array
    {
        $schoolId = $this->authorize($actor, $school, 'platform.billing.manage');
        $invoice = DB::transaction(function () use ($actor, $schoolId, $invoiceId, $transition, $reason): PlatformBillingInvoice {
            $invoice = $this->invoiceRecord($invoiceId, $schoolId, true);
            if ($transition === 'issue' && $invoice->status === 'draft') {
                $invoice->update(['status' => 'issued', 'issued_at' => now()]);
                $this->audit($actor, 'platform.billing.invoice.issued', $invoice, ['from' => 'draft', 'to' => 'issued']);
            } elseif ($transition === 'void' && in_array($this->effectiveInvoiceStatus($invoice), ['draft', 'issued', 'overdue'], true)) {
                $invoice->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $actor->getAuthIdentifier(), 'void_reason' => $reason]);
                $this->audit($actor, 'platform.billing.invoice.voided', $invoice, ['to' => 'voided']);
            } elseif ($invoice->status !== $transition) {
                throw new ModelNotFoundException;
            }

            return $invoice;
        });

        return ['invoice' => $this->invoiceData($invoice)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{payment: array<string, mixed>}
     */
    public function createPayment(Authenticatable $actor, string $school, string $invoiceId, array $input): array
    {
        $schoolId = $this->authorize($actor, $school, 'platform.billing.payments.manage', true);
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        $fingerprint = hash('sha256', json_encode([$schoolId, $invoiceId, $input['provider'], $input['amount_minor_units'], $input['currency']], JSON_THROW_ON_ERROR));
        $keyHash = hash_hmac('sha256', 'platform-billing-payment|'.$schoolId.'|'.$key, (string) config('app.key'));
        $payment = DB::transaction(function () use ($actor, $schoolId, $invoiceId, $input, $fingerprint, $keyHash): PlatformBillingPayment {
            $existing = PlatformBillingPayment::query()->withoutGlobalScope('trusted_tenant')->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing instanceof PlatformBillingPayment) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException;
                }

                return $existing;
            }
            $invoice = $this->invoiceRecord($invoiceId, $schoolId, true);
            if ($invoice->status !== 'issued' || (int) $input['amount_minor_units'] !== (int) $invoice->amount_minor_units || $input['currency'] !== $invoice->currency) {
                throw new ModelNotFoundException;
            }
            $payment = PlatformBillingPayment::query()->withoutGlobalScope('trusted_tenant')->create([
                'school_id' => $schoolId, 'platform_billing_invoice_id' => $invoice->getKey(), 'provider' => $input['provider'],
                'amount_minor_units' => (int) $input['amount_minor_units'], 'currency' => $input['currency'], 'status' => 'pending',
                'idempotency_key_hash' => $keyHash, 'request_fingerprint' => $fingerprint, 'created_by' => $actor->getAuthIdentifier(), 'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, 'platform.billing.payment.created', $payment, ['to' => 'pending']);

            return $payment;
        });

        return ['payment' => $this->paymentData($payment)];
    }

    /** @return array{payment: array<string, mixed>} */
    public function payment(Authenticatable $actor, string $school, string $invoiceId, string $paymentId): array
    {
        $schoolId = $this->authorize($actor, $school, 'platform.billing.read');
        $invoice = $this->invoiceRecord($invoiceId, $schoolId);
        $payment = PlatformBillingPayment::query()->withoutGlobalScope('trusted_tenant')->where('school_id', $schoolId)->where('platform_billing_invoice_id', $invoice->getKey())->where('public_id', $paymentId)->firstOrFail();

        return ['payment' => $this->paymentData($payment)];
    }

    /** @return array{payment: array<string, mixed>} */
    public function refund(Authenticatable $actor, string $school, string $paymentId, string $reason): array
    {
        $schoolId = $this->authorize($actor, $school, 'platform.billing.refunds.manage', true);
        $payment = DB::transaction(function () use ($actor, $schoolId, $paymentId, $reason): PlatformBillingPayment {
            $payment = PlatformBillingPayment::query()->withoutGlobalScope('trusted_tenant')->where('school_id', $schoolId)->where('public_id', $paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'refunded') {
                return $payment;
            }
            if ($payment->status !== 'completed') {
                throw new ModelNotFoundException;
            }
            $payment->update(['status' => 'refunded', 'refunded_at' => now()]);
            $this->audit($actor, 'platform.billing.payment.refunded', $payment, ['from' => 'completed', 'to' => 'refunded', 'reason' => $reason]);

            return $payment;
        });

        return ['payment' => $this->paymentData($payment)];
    }

    /**
     * @param  array{event_id: string, event_type: string, payment_id: string, invoice_id: string, school_id: string, currency: string, amount_minor_units: int, occurred_at: string}  $event
     * @return array{webhook: array<string, mixed>}
     */
    public function processWebhook(string $provider, array $event, string $fingerprint, string $eventHash): array
    {
        return DB::transaction(function () use ($provider, $event, $fingerprint, $eventHash): array {
            $existing = PlatformBillingWebhookEvent::query()->where('provider', $provider)->where('event_id_hash', $eventHash)->lockForUpdate()->first();
            if ($existing instanceof PlatformBillingWebhookEvent) {
                if ($existing->payload_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException;
                }

                return ['webhook' => ['status' => 'duplicate', 'event_id' => (string) $existing->public_id]];
            }
            $payment = PlatformBillingPayment::query()->withoutGlobalScope('trusted_tenant')->where('public_id', $event['payment_id'])->lockForUpdate()->first();
            $invoice = $payment instanceof PlatformBillingPayment ? PlatformBillingInvoice::query()->withoutGlobalScope('trusted_tenant')->find($payment->platform_billing_invoice_id) : null;
            if (! $payment instanceof PlatformBillingPayment || ! $invoice instanceof PlatformBillingInvoice || (string) $invoice->public_id !== $event['invoice_id'] || (int) $payment->school_id !== $this->schoolIdByPublicId($event['school_id']) || $payment->currency !== $event['currency'] || (int) $payment->amount_minor_units !== $event['amount_minor_units']) {
                throw new ModelNotFoundException;
            }
            $target = ['payment.completed' => 'completed', 'payment.failed' => 'failed', 'payment.cancelled' => 'cancelled', 'payment.refunded' => 'refunded'][$event['event_type']] ?? null;
            if ($target === null || ($payment->status !== 'pending' && $payment->status !== $target)) {
                throw new ConflictHttpException;
            }
            $from = $payment->status;
            if ($payment->status !== $target) {
                $payment->update(['status' => $target, 'completed_at' => $target === 'completed' ? now() : null, 'failed_at' => $target === 'failed' ? now() : null, 'cancelled_at' => $target === 'cancelled' ? now() : null, 'refunded_at' => $target === 'refunded' ? now() : null]);
                if ($target === 'completed') {
                    $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                }
            }
            $stored = PlatformBillingWebhookEvent::query()->create(['school_id' => $payment->school_id, 'platform_billing_payment_id' => $payment->getKey(), 'provider' => $provider, 'event_id_hash' => $eventHash, 'payload_fingerprint' => $fingerprint, 'event_type' => $event['event_type'], 'status' => 'accepted', 'request_id' => Context::get('request_id'), 'observed_at' => CarbonImmutable::parse($event['occurred_at'])]);
            $this->audit->execute(new AuditEventData(action: 'platform.billing.webhook.accepted', subjectType: 'platform_billing_payment', subjectPublicId: (string) $payment->public_id, schoolId: (int) $payment->school_id, actorId: null, requestId: Context::get('request_id'), stateTransition: ['from' => $from, 'to' => $target], metadata: ['provider' => $provider, 'event_type' => $event['event_type']]));

            return ['webhook' => ['status' => 'accepted', 'event_id' => (string) $stored->public_id, 'payment_status' => $payment->status, 'transition' => ['from' => $from, 'to' => $target]]];
        });
    }

    private function authorize(Authenticatable $actor, ?string $school, string $permission, bool $mutation = false): int
    {
        if ($school !== null && $this->platformPermissions->allows($actor, $permission)) {
            return $this->activeSchoolId($school);
        }
        $schoolPermission = $mutation ? 'finance.invoices.manage' : 'finance.invoices.read';
        if ($school === null || ! $this->schoolPermissions->allows($actor, $school, $schoolPermission)) {
            throw new ModelNotFoundException;
        }

        return $this->activeSchoolId($school);
    }

    private function activeSchoolId(string $school): int
    {
        $record = DB::table('schools')->where('public_id', $school)->where('status', 'active')->first();
        if ($record === null) {
            throw new ModelNotFoundException;
        }

        return (int) $record->id;
    }

    private function schoolIdByPublicId(string $school): int
    {
        $record = DB::table('schools')->where('public_id', $school)->first();
        if ($record === null) {
            throw new ModelNotFoundException;
        }

        return (int) $record->id;
    }

    private function invoiceRecord(string $id, int $schoolId, bool $lock = false): PlatformBillingInvoice
    {
        $query = PlatformBillingInvoice::query()->withoutGlobalScope('trusted_tenant')->where('school_id', $schoolId)->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function invoiceData(PlatformBillingInvoice $invoice): array
    {
        return ['id' => (string) $invoice->public_id, 'school' => $this->schoolPublicId((int) $invoice->school_id), 'invoice_number' => (string) $invoice->invoice_number, 'plan' => ['code' => $invoice->plan_code_snapshot, 'name' => $invoice->plan_name_snapshot, 'version' => (int) $invoice->plan_version_snapshot], 'amount_minor_units' => (int) $invoice->amount_minor_units, 'currency' => (string) $invoice->currency, 'status' => $this->effectiveInvoiceStatus($invoice), 'issued_at' => $this->timestamp($invoice->issued_at), 'due_at' => $this->timestamp($invoice->due_at), 'paid_at' => $this->timestamp($invoice->paid_at), 'voided_at' => $this->timestamp($invoice->voided_at)];
    }

    private function effectiveInvoiceStatus(PlatformBillingInvoice $invoice): string
    {
        if ($invoice->status === 'issued' && $invoice->due_at !== null && CarbonImmutable::parse((string) $invoice->due_at)->isPast()) {
            return 'overdue';
        }

        return (string) $invoice->status;
    }

    /** @return array<string, mixed> */
    private function paymentData(PlatformBillingPayment $payment): array
    {
        return ['id' => (string) $payment->public_id, 'status' => (string) $payment->status, 'provider' => (string) $payment->provider, 'amount_minor_units' => (int) $payment->amount_minor_units, 'currency' => (string) $payment->currency, 'completed_at' => $this->timestamp($payment->completed_at), 'failed_at' => $this->timestamp($payment->failed_at), 'cancelled_at' => $this->timestamp($payment->cancelled_at), 'refunded_at' => $this->timestamp($payment->refunded_at)];
    }

    private function schoolPublicId(int $schoolId): string
    {
        return (string) DB::table('schools')->where('id', $schoolId)->value('public_id');
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    /** @param array<string, mixed> $transition */
    private function audit(Authenticatable $actor, string $action, object $subject, array $transition): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'platform_billing', subjectPublicId: (string) $subject->public_id, schoolId: (int) $subject->school_id, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), stateTransition: $transition, metadata: []));
    }
}
