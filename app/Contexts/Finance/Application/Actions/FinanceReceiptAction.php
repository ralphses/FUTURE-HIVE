<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Application\Services\FinancePeriodGuard;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentEvidence;
use App\Contexts\Finance\Domain\Models\FinanceReceipt;
use App\Contexts\Finance\Domain\Models\FinanceReceiptSequence;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class FinanceReceiptAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{receipts: list<array<string, mixed>>} */
    public function list(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.receipts.read');

        return ['receipts' => FinanceReceipt::query()->with('invoice')->latest('id')->get()->map(fn (FinanceReceipt $receipt): array => $this->data($receipt))->all()];
    }

    /** @param array{source_type: string, source_public_id: string, description?: string|null} $input
     * @return array{receipt: array<string, mixed>}
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'finance.receipts.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $input): array {
            $sourceType = (string) $input['source_type'];
            $sourceId = (string) $input['source_public_id'];
            $existing = FinanceReceipt::query()->where('school_id', $context->schoolId)->where('source_type', $sourceType)->where('source_public_id', $sourceId)->lockForUpdate()->first();
            if ($existing instanceof FinanceReceipt) {
                return ['receipt' => $this->data($existing->load('invoice'))];
            }

            [$invoice, $amount, $currency, $allocationId, $evidenceId] = $this->source($context->schoolId, $sourceType, $sourceId);
            FinancePeriodGuard::assertOpenForInvoice($invoice);
            $sequence = FinanceReceiptSequence::query()->where('school_id', $context->schoolId)->lockForUpdate()->first();
            if (! $sequence instanceof FinanceReceiptSequence) {
                $sequence = FinanceReceiptSequence::query()->create(['school_id' => $context->schoolId, 'next_number' => 0]);
                $sequence = FinanceReceiptSequence::query()->whereKey($sequence->getKey())->lockForUpdate()->firstOrFail();
            }
            $nextNumber = ((int) $sequence->next_number) + 1;
            $sequence->update(['next_number' => $nextNumber]);
            $receipt = FinanceReceipt::query()->create([
                'school_id' => $context->schoolId,
                'receipt_number' => 'RCT-'.str_pad((string) $nextNumber, 8, '0', STR_PAD_LEFT),
                'receipt_sequence' => $nextNumber,
                'fee_invoice_id' => $invoice->getKey(),
                'source_type' => $sourceType,
                'source_public_id' => $sourceId,
                'fee_payment_allocation_id' => $allocationId,
                'fee_payment_evidence_id' => $evidenceId,
                'amount_minor_units' => $amount,
                'currency' => $currency,
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
                'description' => $input['description'] ?? null,
                'issued_by' => $actor->getKey(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->record($actor, 'finance.receipt_issued', $receipt, ['to' => 'issued', 'source_type' => $sourceType]);

            return ['receipt' => $this->data($receipt->load('invoice'))];
        });
    }

    /** @return array{receipt: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $receiptId): array
    {
        $this->authorize($actor, $school, 'finance.receipts.read');
        $receipt = FinanceReceipt::query()->where('public_id', $receiptId)->with('invoice')->firstOrFail();

        return ['receipt' => $this->data($receipt)];
    }

    /** @return array{0: FeeInvoice, 1: int, 2: string, 3: int|null, 4: int|null} */
    private function source(int $schoolId, string $sourceType, string $sourceId): array
    {
        if ($sourceType === 'payment_allocation') {
            $allocation = FeePaymentAllocation::query()->where('school_id', $schoolId)->where('public_id', $sourceId)->where('status', 'allocated')->lockForUpdate()->first();
            if (! $allocation instanceof FeePaymentAllocation) {
                throw new ModelNotFoundException;
            }
            $invoice = FeeInvoice::query()->whereKey($allocation->fee_invoice_id)->where('school_id', $schoolId)->where('status', 'issued')->lockForUpdate()->first();
            if (! $invoice instanceof FeeInvoice || (string) $allocation->currency !== (string) $invoice->currency) {
                throw new ModelNotFoundException;
            }

            return [$invoice, (int) $allocation->amount_minor_units, (string) $allocation->currency, $allocation->getKey(), null];
        }

        $evidence = FeePaymentEvidence::query()->where('school_id', $schoolId)->where('public_id', $sourceId)->where('status', 'verified')->lockForUpdate()->first();
        if (! $evidence instanceof FeePaymentEvidence) {
            throw new ModelNotFoundException;
        }
        $invoice = FeeInvoice::query()->whereKey($evidence->fee_invoice_id)->where('school_id', $schoolId)->where('status', 'issued')->lockForUpdate()->first();
        if (! $invoice instanceof FeeInvoice || (string) $evidence->currency !== (string) $invoice->currency) {
            throw new ModelNotFoundException;
        }

        return [$invoice, (int) $evidence->amount_minor_units, (string) $evidence->currency, null, $evidence->getKey()];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @return array{id: string, receipt_number: string, invoice_id: string, source_type: string, source_id: string, amount_minor_units: int, currency: string, issue_date: string|null, status: string, description: string|null, created_at: string|null} */
    private function data(FinanceReceipt $receipt): array
    {
        $invoiceId = $receipt->invoice?->public_id;
        $description = $receipt->description;

        return ['id' => (string) $receipt->public_id, 'receipt_number' => (string) $receipt->receipt_number, 'invoice_id' => (string) $invoiceId, 'source_type' => (string) $receipt->source_type, 'source_id' => (string) $receipt->source_public_id, 'amount_minor_units' => (int) $receipt->amount_minor_units, 'currency' => (string) $receipt->currency, 'issue_date' => blank($receipt->issue_date) ? null : CarbonImmutable::parse((string) $receipt->issue_date)->toDateString(), 'status' => (string) $receipt->status, 'description' => $description === null ? null : (string) $description, 'created_at' => $receipt->created_at === null ? null : $receipt->created_at->toAtomString()];
    }

    /** @param array<string, mixed> $transition */
    private function record(UserIdentity $actor, string $action, FinanceReceipt $receipt, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'finance_receipt', subjectPublicId: (string) $receipt->public_id, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
