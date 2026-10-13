<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentEvidence;
use App\Contexts\Finance\Domain\Models\FinanceCompensationRequest;
use App\Contexts\Finance\Domain\Models\FinanceReceipt;
use App\Contexts\Finance\Domain\Models\FinancialLedgerBatch;
use App\Contexts\Finance\Domain\Models\FinancialLedgerEntry;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceCompensationAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function list(UserIdentity $actor, string $school, string $kind): array
    {
        $this->authorize($actor, $school, 'finance.'.$kind.'s.read');

        return ['items' => FinanceCompensationRequest::query()->where('kind', $kind)->latest('id')->get()->map(fn (FinanceCompensationRequest $request): array => $this->data($request))->all()];
    }

    /** @param array{source_type: string, source_public_id: string, amount_minor_units: int, currency: string, reason: string} $input
     * @return array{item: array<string, mixed>}
     */
    public function create(UserIdentity $actor, string $school, string $kind, array $input): array
    {
        $this->authorize($actor, $school, 'finance.'.$kind.'s.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $kind, $input): array {
            [$invoice, $sourceAmount, $currency] = $this->source($context->schoolId, (string) $input['source_type'], (string) $input['source_public_id']);
            $amount = (int) $input['amount_minor_units'];
            if (strtoupper((string) $input['currency']) !== $currency || $amount > $sourceAmount) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The compensation amount is invalid for the source.']]);
            }
            $existing = FinanceCompensationRequest::query()->where('school_id', $context->schoolId)->where('kind', $kind)->where('source_type', $input['source_type'])->where('source_public_id', $input['source_public_id'])->lockForUpdate()->first();
            if ($existing instanceof FinanceCompensationRequest) {
                return ['item' => $this->data($existing)];
            }
            $prior = (int) FinanceCompensationRequest::query()->where('school_id', $context->schoolId)->where('kind', $kind)->where('source_type', $input['source_type'])->where('source_public_id', $input['source_public_id'])->whereIn('status', ['requested', 'approved', 'applied'])->sum('amount_minor_units');
            if ($prior + $amount > $sourceAmount) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The source has insufficient uncompensated value.']]);
            }
            $request = FinanceCompensationRequest::query()->create(['school_id' => $context->schoolId, 'kind' => $kind, 'source_type' => $input['source_type'], 'source_public_id' => $input['source_public_id'], 'fee_invoice_id' => $invoice->getKey(), 'currency' => $currency, 'amount_minor_units' => $amount, 'reason' => trim((string) $input['reason']), 'status' => 'requested', 'requested_by' => $actor->getKey(), 'requested_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->record($actor, 'finance.'.$kind.'_requested', $request, ['to' => 'requested']);

            return ['item' => $this->data($request)];
        });
    }

    /** @return array{item: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $kind, string $id): array
    {
        $this->authorize($actor, $school, 'finance.'.$kind.'s.read');
        $request = $this->request($kind, $id);

        return ['item' => $this->data($request)];
    }

    /** @return array{item: array<string, mixed>} */
    public function approve(UserIdentity $actor, string $school, string $kind, string $id): array
    {
        $this->authorize($actor, $school, 'finance.'.$kind.'s.approve');

        return DB::transaction(function () use ($actor, $kind, $id): array {
            $request = $this->request($kind, $id, true);
            if ($request->status === 'approved') {
                return ['item' => $this->data($request)];
            }
            if ($request->status !== 'requested' || (int) $request->requested_by === (int) $actor->getKey()) {
                throw new ModelNotFoundException;
            }
            $request->update(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now()]);
            $this->record($actor, 'finance.'.$kind.'_approved', $request, ['from' => 'requested', 'to' => 'approved']);

            return ['item' => $this->data($request->fresh())];
        });
    }

    /** @return array{item: array<string, mixed>} */
    public function reject(UserIdentity $actor, string $school, string $kind, string $id, string $reason): array
    {
        $this->authorize($actor, $school, 'finance.'.$kind.'s.approve');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 240 || preg_match('/^[A-Za-z0-9 .,\'"()_:-]+$/', $reason) !== 1) {
            throw ValidationException::withMessages(['reason' => ['The rejection reason is invalid.']]);
        }

        return DB::transaction(function () use ($actor, $kind, $id, $reason): array {
            $request = $this->request($kind, $id, true);
            if ($request->status === 'rejected') {
                return ['item' => $this->data($request)];
            }
            if (! in_array((string) $request->status, ['requested', 'approved'], true)) {
                throw new ModelNotFoundException;
            }
            $request->update(['status' => 'rejected', 'rejected_by' => $actor->getKey(), 'rejected_at' => now(), 'reason' => $reason]);
            $this->record($actor, 'finance.'.$kind.'_rejected', $request, ['to' => 'rejected']);

            return ['item' => $this->data($request->fresh())];
        });
    }

    /** @return array{item: array<string, mixed>} */
    public function apply(UserIdentity $actor, string $school, string $kind, string $id): array
    {
        $this->authorize($actor, $school, 'finance.'.$kind.'s.approve');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $kind, $id): array {
            $request = $this->request($kind, $id, true);
            if ($request->status === 'applied') {
                return ['item' => $this->data($request)];
            }
            if ($request->status !== 'approved') {
                throw new ModelNotFoundException;
            }
            $ledger = FinancialLedgerBatch::query()->where('school_id', $context->schoolId)->where('source_type', $kind)->where('source_public_id', $request->public_id)->lockForUpdate()->first();
            if (! $ledger instanceof FinancialLedgerBatch) {
                $fingerprint = hash_hmac('sha256', 'finance-'.$kind.'|'.$context->schoolId.'|'.$request->public_id, (string) config('app.key'));
                $ledger = FinancialLedgerBatch::query()->create(['school_id' => $context->schoolId, 'currency' => $request->currency, 'source_type' => $kind, 'source_public_id' => $request->public_id, 'source_fingerprint' => $fingerprint, 'effective_date' => now()->toDateString(), 'posting_date' => now()->toDateString(), 'status' => 'posted', 'description' => $kind.' compensation', 'posted_by' => $actor->getKey(), 'posted_at' => now(), 'request_id' => Context::get('request_id')]);
                FinancialLedgerEntry::query()->create(['school_id' => $context->schoolId, 'financial_ledger_batch_id' => $ledger->getKey(), 'account_code' => $kind.'_contra', 'direction' => 'debit', 'amount_minor_units' => $request->amount_minor_units, 'currency' => $request->currency, 'description' => $kind.' compensation']);
                FinancialLedgerEntry::query()->create(['school_id' => $context->schoolId, 'financial_ledger_batch_id' => $ledger->getKey(), 'account_code' => 'settlement', 'direction' => 'credit', 'amount_minor_units' => $request->amount_minor_units, 'currency' => $request->currency, 'description' => $kind.' compensation']);
            }
            $request->update(['status' => 'applied', 'applied_by' => $actor->getKey(), 'applied_at' => now(), 'ledger_batch_public_id' => $ledger->public_id]);
            $this->record($actor, 'finance.'.$kind.'_applied', $request, ['from' => 'approved', 'to' => 'applied']);

            return ['item' => $this->data($request->fresh())];
        });
    }

    /** @return array{0: FeeInvoice, 1: int, 2: string} */
    private function source(int $schoolId, string $type, string $id): array
    {
        if ($type === 'receipt') {
            $source = FinanceReceipt::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'issued')->lockForUpdate()->first();
            if (! $source instanceof FinanceReceipt) {
                throw new ModelNotFoundException;
            }
            $invoice = FeeInvoice::query()->whereKey($source->fee_invoice_id)->where('school_id', $schoolId)->where('status', 'issued')->lockForUpdate()->first();

            return $invoice instanceof FeeInvoice ? [$invoice, (int) $source->amount_minor_units, (string) $source->currency] : throw new ModelNotFoundException;
        }
        if ($type === 'payment_allocation') {
            $source = FeePaymentAllocation::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'allocated')->lockForUpdate()->first();
            $invoice = FeeInvoice::query()->whereKey($source->fee_invoice_id)->where('school_id', $schoolId)->where('status', 'issued')->lockForUpdate()->first();

            return $invoice instanceof FeeInvoice ? [$invoice, (int) $source->amount_minor_units, (string) $source->currency] : throw new ModelNotFoundException;
        }
        if ($type === 'payment_evidence') {
            $source = FeePaymentEvidence::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'verified')->lockForUpdate()->first();
            $invoice = FeeInvoice::query()->whereKey($source->fee_invoice_id)->where('school_id', $schoolId)->where('status', 'issued')->lockForUpdate()->first();

            return $invoice instanceof FeeInvoice ? [$invoice, (int) $source->amount_minor_units, (string) $source->currency] : throw new ModelNotFoundException;
        }
        $source = FinancialLedgerBatch::query()->where('public_id', $id)->where('school_id', $schoolId)->where('status', 'posted')->with('entries')->lockForUpdate()->first();
        $invoice = null;
        if ($source instanceof FinancialLedgerBatch && $source->source_type === 'invoice') {
            $invoice = FeeInvoice::query()->where('public_id', $source->source_public_id)->where('school_id', $schoolId)->where('status', 'issued')->first();
        }
        if (! $source instanceof FinancialLedgerBatch || ! $invoice instanceof FeeInvoice) {
            throw new ModelNotFoundException;
        }

        return [$invoice, (int) $source->entries->where('direction', 'debit')->sum('amount_minor_units'), (string) $source->currency];
    }

    private function request(string $kind, string $id, bool $lock = false): FinanceCompensationRequest
    {
        $query = FinanceCompensationRequest::query()->where('kind', $kind)->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @return array<string, mixed> */
    private function data(FinanceCompensationRequest $request): array
    {
        $request->loadMissing('invoice');

        return ['id' => (string) $request->public_id, 'kind' => (string) $request->kind, 'source_type' => (string) $request->source_type, 'source_id' => (string) $request->source_public_id, 'invoice_id' => (string) $request->invoice->public_id, 'amount_minor_units' => (int) $request->amount_minor_units, 'currency' => (string) $request->currency, 'reason' => (string) $request->reason, 'status' => (string) $request->status, 'ledger_batch_id' => $request->ledger_batch_public_id, 'requested_at' => $this->atom($request->requested_at), 'approved_at' => $this->atom($request->approved_at), 'rejected_at' => $this->atom($request->rejected_at), 'applied_at' => $this->atom($request->applied_at)];
    }

    private function atom(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toAtomString() : ($value === null ? null : (string) $value);
    }

    /** @param array<string, string|int> $transition */
    private function record(UserIdentity $actor, string $action, FinanceCompensationRequest $request, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'finance_compensation_request', subjectPublicId: (string) $request->public_id, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
