<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Application\Services\FinancePeriodGuard;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeeInvoiceAdjustment;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentEvidence;
use App\Contexts\Finance\Domain\Models\FinancialLedgerBatch;
use App\Contexts\Finance\Domain\Models\FinancialLedgerEntry;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\FinancialOpeningBalance;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancialLedgerAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function list(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.ledger.read');

        return ['items' => FinancialLedgerBatch::query()->with('entries')->latest('id')->get()->map(fn (FinancialLedgerBatch $batch): array => $this->data($batch))->all()];
    }

    /** @param array{currency: string, source_type: string, source_public_id: string, effective_date: string, description?: string|null, entries: list<array{account_code: string, direction: string, amount_minor_units: int, description?: string|null}>} $input
     * @return array{ledger_batch: array<string, mixed>}
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'finance.ledger.manage');
        $context = TenantContext::require();
        $currency = strtoupper((string) $input['currency']);
        $sourceType = (string) $input['source_type'];
        $sourceId = (string) $input['source_public_id'];
        $entries = $input['entries'];
        $debit = collect($entries)->where('direction', 'debit')->sum('amount_minor_units');
        $credit = collect($entries)->where('direction', 'credit')->sum('amount_minor_units');
        if ($debit !== $credit || $debit < 1) {
            throw ValidationException::withMessages(['entries' => ['Ledger entries must contain equal positive debit and credit totals.']]);
        }

        return DB::transaction(function () use ($actor, $context, $currency, $sourceType, $sourceId, $input, $entries): array {
            $this->source($context->schoolId, $sourceType, $sourceId);
            if ($sourceType === 'invoice') {
                FinancePeriodGuard::assertOpenForInvoice(FeeInvoice::query()->where('school_id', $context->schoolId)->where('public_id', $sourceId)->firstOrFail());
            }
            $fingerprint = hash_hmac('sha256', json_encode(['school' => $context->schoolId, 'source_type' => $sourceType, 'source_public_id' => $sourceId, 'currency' => $currency, 'effective_date' => $input['effective_date'], 'entries' => $entries], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
            $existing = FinancialLedgerBatch::query()->where('source_fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($existing instanceof FinancialLedgerBatch) {
                return ['ledger_batch' => $this->data($existing->load('entries'))];
            }
            $batch = FinancialLedgerBatch::query()->create(['school_id' => $context->schoolId, 'currency' => $currency, 'source_type' => $sourceType, 'source_public_id' => $sourceId, 'source_fingerprint' => $fingerprint, 'effective_date' => $input['effective_date'], 'status' => 'pending', 'description' => $input['description'] ?? null, 'request_id' => Context::get('request_id')]);
            foreach ($entries as $entry) {
                FinancialLedgerEntry::query()->create(['school_id' => $context->schoolId, 'financial_ledger_batch_id' => $batch->getKey(), 'account_code' => $entry['account_code'], 'direction' => $entry['direction'], 'amount_minor_units' => $entry['amount_minor_units'], 'currency' => $currency, 'description' => $entry['description'] ?? null]);
            }
            $this->record($actor, 'finance.ledger_batch_created', $batch, ['to' => 'pending', 'entry_count' => count($entries)]);

            return ['ledger_batch' => $this->data($batch->load('entries'))];
        });
    }

    /** @return array{ledger_batch: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $batchId): array
    {
        $this->authorize($actor, $school, 'finance.ledger.read');
        $batch = FinancialLedgerBatch::query()->where('public_id', $batchId)->with('entries')->firstOrFail();

        return ['ledger_batch' => $this->data($batch)];
    }

    /** @return array{ledger_batch: array<string, mixed>} */
    public function post(UserIdentity $actor, string $school, string $batchId): array
    {
        $this->authorize($actor, $school, 'finance.ledger.manage');

        return DB::transaction(function () use ($actor, $batchId): array {
            $batch = FinancialLedgerBatch::query()->where('public_id', $batchId)->with('entries')->lockForUpdate()->firstOrFail();
            if ($batch->status === 'posted') {
                return ['ledger_batch' => $this->data($batch)];
            }
            if ($batch->status !== 'pending') {
                throw new ModelNotFoundException;
            }
            if ($batch->source_type === 'invoice') {
                FinancePeriodGuard::assertOpenForInvoice(FeeInvoice::query()->where('school_id', $batch->school_id)->where('public_id', $batch->source_public_id)->firstOrFail());
            }
            $debit = $batch->entries->where('direction', 'debit')->sum('amount_minor_units');
            $credit = $batch->entries->where('direction', 'credit')->sum('amount_minor_units');
            if ($debit !== $credit || $debit < 1) {
                throw ValidationException::withMessages(['entries' => ['The ledger batch is not balanced.']]);
            }
            $batch->update(['status' => 'posted', 'posting_date' => now()->toDateString(), 'posted_by' => $actor->getKey(), 'posted_at' => now()]);
            $this->record($actor, 'finance.ledger_batch_posted', $batch, ['from' => 'pending', 'to' => 'posted']);

            return ['ledger_batch' => $this->data($batch->fresh('entries'))];
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function source(int $schoolId, string $type, string $publicId): void
    {
        $model = match ($type) {
            'invoice' => FeeInvoice::query()->where('public_id', $publicId)->where('status', 'issued')->first(),
            'payment_allocation' => FeePaymentAllocation::query()->where('public_id', $publicId)->where('status', 'allocated')->first(),
            'opening_balance' => FinancialOpeningBalance::query()->where('public_id', $publicId)->where('status', '!=', 'rejected')->first(),
            'adjustment' => FeeInvoiceAdjustment::query()->where('public_id', $publicId)->where('status', 'applied')->first(),
            'payment_evidence' => FeePaymentEvidence::query()->where('public_id', $publicId)->where('status', 'verified')->first(),
            default => null,
        };
        if ($model === null || (int) $model->school_id !== $schoolId) {
            throw new ModelNotFoundException;
        }
    }

    /** @return array<string, mixed> */
    private function data(FinancialLedgerBatch $batch): array
    {
        return ['id' => (string) $batch->public_id, 'currency' => (string) $batch->currency, 'source_type' => (string) $batch->source_type, 'source_id' => (string) $batch->source_public_id, 'effective_date' => CarbonImmutable::parse((string) $batch->effective_date)->toDateString(), 'posting_date' => blank($batch->posting_date) ? null : CarbonImmutable::parse((string) $batch->posting_date)->toDateString(), 'status' => (string) $batch->status, 'description' => $batch->description, 'entries' => $batch->entries->map(fn (FinancialLedgerEntry $entry): array => ['id' => (string) $entry->public_id, 'account_code' => (string) $entry->account_code, 'direction' => (string) $entry->direction, 'amount_minor_units' => (int) $entry->amount_minor_units, 'currency' => (string) $entry->currency, 'description' => $entry->description])->all(), 'created_at' => $batch->created_at?->toAtomString()];
    }

    /** @param array<string, mixed> $transition */
    private function record(UserIdentity $actor, string $action, FinancialLedgerBatch $batch, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'financial_ledger_batch', subjectPublicId: (string) $batch->public_id, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
