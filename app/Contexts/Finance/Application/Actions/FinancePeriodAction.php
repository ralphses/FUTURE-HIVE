<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentEvidence;
use App\Contexts\Finance\Domain\Models\FeePaymentIntent;
use App\Contexts\Finance\Domain\Models\FinancePeriod;
use App\Contexts\Finance\Domain\Models\FinancePostCloseAdjustment;
use App\Contexts\Finance\Domain\Models\FinanceReceipt;
use App\Contexts\Finance\Domain\Models\FinanceReconciliationItem;
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

final class FinancePeriodAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{periods: list<array<string, mixed>>} */
    public function list(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.periods.read');

        return ['periods' => FinancePeriod::query()->latest('id')->get()->map(fn (FinancePeriod $period): array => $this->periodData($period))->all()];
    }

    /** @return array{period: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $sessionId, string $termId): array
    {
        $this->authorize($actor, $school, 'finance.periods.read');
        $context = TenantContext::require();
        $term = $this->term($context->schoolId, $sessionId, $termId);
        $period = $this->period($context->schoolId, $term, false);

        return ['period' => $this->periodData($period)];
    }

    /** @return array{period: array<string, mixed>} */
    public function startClose(UserIdentity $actor, string $school, string $sessionId, string $termId): array
    {
        $this->authorize($actor, $school, 'finance.periods.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $sessionId, $termId): array {
            $term = $this->term($context->schoolId, $sessionId, $termId);
            $period = $this->period($context->schoolId, $term, true);
            if (in_array((string) $period->status, ['closed', 'reopened'], true)) {
                return ['period' => $this->periodData($period)];
            }
            $blockers = $this->blockers($context->schoolId);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['period' => ['The financial period cannot close while unresolved financial work remains.']]);
            }
            if ((string) $period->status === 'open') {
                $period->update(['status' => 'closing', 'started_by' => $actor->getKey(), 'closing_started_at' => now(), 'request_id' => Context::get('request_id')]);
                $this->record($actor, 'finance.period_close_started', $period, ['from' => 'open', 'to' => 'closing']);
            }

            return ['period' => $this->periodData($period->fresh())];
        });
    }

    /** @return array{period: array<string, mixed>} */
    public function completeClose(UserIdentity $actor, string $school, string $sessionId, string $termId): array
    {
        $this->authorize($actor, $school, 'finance.periods.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $sessionId, $termId): array {
            $term = $this->term($context->schoolId, $sessionId, $termId);
            $period = $this->period($context->schoolId, $term, true);
            if ((string) $period->status === 'closed') {
                return ['period' => $this->periodData($period)];
            }
            if ((string) $period->status !== 'closing') {
                throw new ModelNotFoundException;
            }
            $blockers = $this->blockers($context->schoolId);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['period' => ['The financial period cannot close while unresolved financial work remains.']]);
            }
            $period->update(['status' => 'closed', 'closed_by' => $actor->getKey(), 'closed_at' => now()]);
            $this->record($actor, 'finance.period_closed', $period, ['from' => 'closing', 'to' => 'closed']);

            return ['period' => $this->periodData($period->fresh())];
        });
    }

    /** @return array{period: array<string, mixed>} */
    public function reopen(UserIdentity $actor, string $school, string $sessionId, string $termId, string $reason): array
    {
        $this->authorize($actor, $school, 'finance.periods.reopen');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 240 || preg_match('/^[A-Za-z0-9 .,\'"()_:-]+$/', $reason) !== 1) {
            throw ValidationException::withMessages(['reason' => ['The reopen reason is invalid.']]);
        }
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $sessionId, $termId, $reason): array {
            $term = $this->term($context->schoolId, $sessionId, $termId);
            $period = $this->period($context->schoolId, $term, true);
            if ((string) $period->status === 'reopened') {
                return ['period' => $this->periodData($period)];
            }
            if ((string) $period->status !== 'closed') {
                throw new ModelNotFoundException;
            }
            $period->update(['status' => 'reopened', 'reopened_by' => $actor->getKey(), 'reopened_at' => now()]);
            $this->record($actor, 'finance.period_reopened', $period, ['from' => 'closed', 'to' => 'reopened', 'reason' => $reason]);

            return ['period' => $this->periodData($period->fresh())];
        });
    }

    /** @return array{adjustments: list<array<string, mixed>>} */
    public function adjustments(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.post-close-adjustments.read');

        return ['adjustments' => FinancePostCloseAdjustment::query()->latest('id')->get()->map(fn (FinancePostCloseAdjustment $adjustment): array => $this->adjustmentData($adjustment))->all()];
    }

    /** @param array{session_id: string, term_id: string, source_type: string, source_public_id: string, amount_minor_units: int, currency: string, reason: string} $input
     * @return array{adjustment: array<string, mixed>}
     */
    public function createAdjustment(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'finance.post-close-adjustments.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $input): array {
            $term = $this->term($context->schoolId, $input['session_id'], $input['term_id']);
            $period = $this->period($context->schoolId, $term, true);
            if (! in_array((string) $period->status, ['closed', 'reopened'], true)) {
                throw new ModelNotFoundException;
            }
            $reason = trim($input['reason']);
            if (mb_strlen($reason) > 240 || preg_match('/^[A-Za-z0-9 .,\'"()_:-]+$/', $reason) !== 1) {
                throw ValidationException::withMessages(['reason' => ['The adjustment reason is invalid.']]);
            }
            [$amount, $currency] = $this->source($context->schoolId, $input['source_type'], $input['source_public_id']);
            if (strtoupper($input['currency']) !== $currency || (int) $input['amount_minor_units'] > $amount) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The post-close adjustment amount is invalid.']]);
            }
            $existing = FinancePostCloseAdjustment::query()->where('school_id', $context->schoolId)->where('source_type', $input['source_type'])->where('source_public_id', $input['source_public_id'])->lockForUpdate()->first();
            if ($existing instanceof FinancePostCloseAdjustment) {
                return ['adjustment' => $this->adjustmentData($existing)];
            }
            $adjustment = FinancePostCloseAdjustment::query()->create(['school_id' => $context->schoolId, 'finance_period_id' => $period->getKey(), 'source_type' => $input['source_type'], 'source_public_id' => $input['source_public_id'], 'amount_minor_units' => $input['amount_minor_units'], 'currency' => $currency, 'reason' => $reason, 'status' => 'requested', 'requested_by' => $actor->getKey(), 'requested_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->record($actor, 'finance.post_close_adjustment_requested', $period, ['to' => 'requested']);

            return ['adjustment' => $this->adjustmentData($adjustment)];
        });
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function showAdjustment(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.post-close-adjustments.read');

        return ['adjustment' => $this->adjustmentData($this->adjustment($id))];
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function approveAdjustment(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.post-close-adjustments.approve');

        return DB::transaction(function () use ($actor, $id): array {
            $adjustment = $this->adjustment($id, true);
            if ((string) $adjustment->status === 'approved') {
                return ['adjustment' => $this->adjustmentData($adjustment)];
            }
            if ((string) $adjustment->status !== 'requested' || (int) $adjustment->requested_by === (int) $actor->getKey()) {
                throw new ModelNotFoundException;
            }
            $adjustment->update(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now()]);
            $this->record($actor, 'finance.post_close_adjustment_approved', $adjustment->period, ['from' => 'requested', 'to' => 'approved']);

            return ['adjustment' => $this->adjustmentData($adjustment->fresh())];
        });
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function rejectAdjustment(UserIdentity $actor, string $school, string $id, string $reason): array
    {
        $this->authorize($actor, $school, 'finance.post-close-adjustments.approve');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 240 || preg_match('/^[A-Za-z0-9 .,\'"()_:-]+$/', $reason) !== 1) {
            throw ValidationException::withMessages(['reason' => ['The rejection reason is invalid.']]);
        }

        return DB::transaction(function () use ($actor, $id, $reason): array {
            $adjustment = $this->adjustment($id, true);
            if ((string) $adjustment->status === 'rejected') {
                return ['adjustment' => $this->adjustmentData($adjustment)];
            }
            if ((string) $adjustment->status !== 'requested') {
                throw new ModelNotFoundException;
            }
            $adjustment->update(['status' => 'rejected', 'rejection_reason' => $reason, 'rejected_at' => now()]);
            $this->record($actor, 'finance.post_close_adjustment_rejected', $adjustment->period, ['from' => 'requested', 'to' => 'rejected']);

            return ['adjustment' => $this->adjustmentData($adjustment->fresh())];
        });
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function applyAdjustment(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.post-close-adjustments.approve');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $id): array {
            $adjustment = $this->adjustment($id, true);
            if ((string) $adjustment->status === 'applied') {
                return ['adjustment' => $this->adjustmentData($adjustment)];
            }
            if ((string) $adjustment->status !== 'approved') {
                throw new ModelNotFoundException;
            }
            $fingerprint = hash_hmac('sha256', 'post-close-adjustment|'.$context->schoolId.'|'.$adjustment->public_id, (string) config('app.key'));
            $batch = FinancialLedgerBatch::query()->where('source_fingerprint', $fingerprint)->lockForUpdate()->first();
            if (! $batch instanceof FinancialLedgerBatch) {
                $batch = FinancialLedgerBatch::query()->create(['school_id' => $context->schoolId, 'currency' => $adjustment->currency, 'source_type' => 'post_close_adjustment', 'source_public_id' => $adjustment->public_id, 'source_fingerprint' => $fingerprint, 'effective_date' => now()->toDateString(), 'posting_date' => now()->toDateString(), 'status' => 'posted', 'description' => 'Post-close financial adjustment', 'posted_by' => $actor->getKey(), 'posted_at' => now(), 'request_id' => Context::get('request_id')]);
                FinancialLedgerEntry::query()->create(['school_id' => $context->schoolId, 'financial_ledger_batch_id' => $batch->getKey(), 'account_code' => 'post_close_adjustment', 'direction' => 'debit', 'amount_minor_units' => $adjustment->amount_minor_units, 'currency' => $adjustment->currency, 'description' => 'Post-close adjustment']);
                FinancialLedgerEntry::query()->create(['school_id' => $context->schoolId, 'financial_ledger_batch_id' => $batch->getKey(), 'account_code' => 'settlement', 'direction' => 'credit', 'amount_minor_units' => $adjustment->amount_minor_units, 'currency' => $adjustment->currency, 'description' => 'Post-close adjustment']);
            }
            $adjustment->update(['status' => 'applied', 'applied_by' => $actor->getKey(), 'applied_at' => now(), 'compensating_ledger_batch_public_id' => $batch->public_id]);
            $this->record($actor, 'finance.post_close_adjustment_applied', $adjustment->period, ['from' => 'approved', 'to' => 'applied']);

            return ['adjustment' => $this->adjustmentData($adjustment->fresh())];
        });
    }

    /** @return list<string> */
    public function blockers(int $schoolId): array
    {
        $blockers = [];
        if (FinanceReconciliationItem::query()->where('school_id', $schoolId)->where('status', 'exception')->exists()) {
            $blockers[] = 'RECONCILIATION_EXCEPTION';
        }
        if (FeePaymentIntent::query()->where('school_id', $schoolId)->whereIn('status', ['created', 'pending'])->exists()) {
            $blockers[] = 'PENDING_PAYMENT_INTENT';
        }
        if (FeePaymentEvidence::query()->where('school_id', $schoolId)->whereIn('status', ['pending', 'under_review'])->exists()) {
            $blockers[] = 'UNREVIEWED_PAYMENT_EVIDENCE';
        }

        return $blockers;
    }

    /** @return array{0: int, 1: string} */
    private function source(int $schoolId, string $type, string $id): array
    {
        $model = match ($type) {
            'invoice' => FeeInvoice::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'issued')->first(),
            'payment_allocation' => FeePaymentAllocation::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'allocated')->first(),
            'payment_evidence' => FeePaymentEvidence::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'verified')->first(),
            'receipt' => FinanceReceipt::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'issued')->first(),
            'ledger_batch' => FinancialLedgerBatch::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'posted')->with('entries')->first(),
            default => null,
        };
        if ($model === null) {
            throw new ModelNotFoundException;
        }
        $amount = (int) ($model->amount_minor_units ?? $model->total_minor_units ?? $model->entries->where('direction', 'debit')->sum('amount_minor_units'));

        return [$amount, (string) $model->currency];
    }

    private function term(int $schoolId, string $sessionId, string $termId): AcademicTerm
    {
        $term = AcademicTerm::query()->where('school_id', $schoolId)->where('public_id', $termId)->whereHas('session', fn ($query) => $query->where('school_id', $schoolId)->where('public_id', $sessionId))->first();
        if (! $term instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return $term;
    }

    private function period(int $schoolId, AcademicTerm $term, bool $lock): FinancePeriod
    {
        $query = FinancePeriod::query()->where('school_id', $schoolId)->where('academic_term_id', $term->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $period = $query->first();
        if ($period instanceof FinancePeriod) {
            return $period;
        }

        return FinancePeriod::query()->create(['school_id' => $schoolId, 'academic_session_id' => $term->academic_session_id, 'academic_term_id' => $term->getKey(), 'status' => 'open']);
    }

    private function adjustment(string $id, bool $lock = false): FinancePostCloseAdjustment
    {
        $query = FinancePostCloseAdjustment::query()->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function periodData(FinancePeriod $period): array
    {
        $session = AcademicSession::query()->find($period->academic_session_id);
        $term = AcademicTerm::query()->find($period->academic_term_id);

        return ['id' => (string) $period->public_id, 'session_id' => (string) $session?->public_id, 'term_id' => (string) $term?->public_id, 'status' => (string) $period->status, 'blockers' => $this->blockers((int) $period->school_id), 'closing_started_at' => $this->atom($period->closing_started_at), 'closed_at' => $this->atom($period->closed_at), 'reopened_at' => $this->atom($period->reopened_at)];
    }

    /** @return array<string, mixed> */
    private function adjustmentData(FinancePostCloseAdjustment $adjustment): array
    {
        return ['id' => (string) $adjustment->public_id, 'source_type' => (string) $adjustment->source_type, 'source_id' => (string) $adjustment->source_public_id, 'amount_minor_units' => (int) $adjustment->amount_minor_units, 'currency' => (string) $adjustment->currency, 'reason' => (string) $adjustment->reason, 'status' => (string) $adjustment->status, 'ledger_batch_id' => $adjustment->compensating_ledger_batch_public_id === null ? null : (string) $adjustment->compensating_ledger_batch_public_id, 'requested_at' => $this->atom($adjustment->requested_at), 'approved_at' => $this->atom($adjustment->approved_at), 'rejected_at' => $this->atom($adjustment->rejected_at), 'applied_at' => $this->atom($adjustment->applied_at)];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @param array<string, string> $transition */
    private function record(UserIdentity $actor, string $action, FinancePeriod $period, array $transition): void
    {
        $this->audit->execute(new AuditEventData(schoolId: (int) $period->school_id, actorId: $actor->getKey(), action: $action, subjectType: 'finance_period', subjectPublicId: (string) $period->public_id, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }

    private function atom(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toAtomString() : ($value === null ? null : (string) $value);
    }
}
