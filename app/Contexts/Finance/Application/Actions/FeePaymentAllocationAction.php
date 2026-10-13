<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Application\Services\FinancePeriodGuard;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeeInvoiceLine;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FeePaymentAllocationAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{allocations: list<array<string, mixed>>} */
    public function list(UserIdentity $actor, string $school, string $invoiceId): array
    {
        $this->authorize($actor, $school, 'finance.payment-allocations.read');
        $invoice = $this->invoice($invoiceId);

        return ['allocations' => FeePaymentAllocation::query()->where('fee_invoice_id', $invoice->getKey())->with(['invoice', 'line'])->orderBy('id')->get()->map(fn (FeePaymentAllocation $allocation): array => $this->data($allocation))->all()];
    }

    /**
     * @param  array{payment_reference: string, currency: string, payment_amount_minor_units: int, amount_minor_units: int, line_id?: string|null}  $input
     * @return array{allocation: array<string, mixed>}
     */
    public function allocate(UserIdentity $actor, string $school, string $invoiceId, array $input): array
    {
        $this->authorize($actor, $school, 'finance.payment-allocations.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $invoiceId, $input): array {
            $invoice = $this->invoice($invoiceId, true);
            FinancePeriodGuard::assertOpenForInvoice($invoice);
            if ($invoice->status !== 'issued') {
                throw new ModelNotFoundException;
            }
            $currency = strtoupper((string) $input['currency']);
            if ($currency !== (string) $invoice->currency) {
                throw ValidationException::withMessages(['currency' => ['The payment currency must match the invoice currency.']]);
            }
            $line = null;
            if (($input['line_id'] ?? null) !== null) {
                $line = FeeInvoiceLine::query()->where('fee_invoice_id', $invoice->getKey())->where('public_id', $input['line_id'])->lockForUpdate()->firstOrFail();
            }
            $existing = FeePaymentAllocation::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->where('payment_reference', $input['payment_reference'])->when($line instanceof FeeInvoiceLine, fn ($query) => $query->where('fee_invoice_line_id', $line->getKey()), fn ($query) => $query->whereNull('fee_invoice_line_id'))->lockForUpdate()->first();
            if ($existing instanceof FeePaymentAllocation) {
                return ['allocation' => $this->data($existing->load('line'))];
            }
            $paymentAmount = (int) $input['payment_amount_minor_units'];
            $amount = (int) $input['amount_minor_units'];
            if ($amount > $paymentAmount) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The allocation cannot exceed the payment amount.']]);
            }
            $paymentAllocated = (int) FeePaymentAllocation::query()->where('school_id', $context->schoolId)->where('payment_reference', $input['payment_reference'])->sum('amount_minor_units');
            if ($paymentAllocated + $amount > $paymentAmount) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The payment has insufficient unallocated funds.']]);
            }
            $targetAmount = $line instanceof FeeInvoiceLine ? (int) $line->amount_minor_units : (int) $invoice->total_minor_units;
            $targetAllocated = FeePaymentAllocation::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->when($line instanceof FeeInvoiceLine, fn ($query) => $query->where('fee_invoice_line_id', $line->getKey()), fn ($query) => $query->whereNull('fee_invoice_line_id'))->sum('amount_minor_units');
            if ($targetAllocated + $amount > $targetAmount) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The allocation exceeds the outstanding invoice balance.']]);
            }
            $invoiceAllocated = (int) FeePaymentAllocation::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->sum('amount_minor_units');
            if ($invoiceAllocated + $amount > (int) $invoice->total_minor_units) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The allocation exceeds the outstanding invoice balance.']]);
            }
            $allocation = FeePaymentAllocation::query()->create(['school_id' => $context->schoolId, 'fee_invoice_id' => $invoice->getKey(), 'fee_invoice_line_id' => $line?->getKey(), 'payment_reference' => $input['payment_reference'], 'allocation_scope_key' => $line instanceof FeeInvoiceLine ? 'line:'.$line->getKey() : 'invoice:'.$invoice->getKey(), 'currency' => $currency, 'payment_amount_minor_units' => $paymentAmount, 'amount_minor_units' => $amount, 'status' => 'allocated', 'allocated_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $this->record($actor, 'finance.payment_allocation_created', 'fee_payment_allocation', (string) $allocation->public_id, ['to' => 'allocated', 'amount_minor_units' => $amount]);

            return ['allocation' => $this->data($allocation->load('line'))];
        });
    }

    /** @return array{allocation: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $invoiceId, string $allocationId): array
    {
        $this->authorize($actor, $school, 'finance.payment-allocations.read');
        $invoice = $this->invoice($invoiceId);
        $allocation = FeePaymentAllocation::query()->where('fee_invoice_id', $invoice->getKey())->where('public_id', $allocationId)->with(['invoice', 'line'])->firstOrFail();

        return ['allocation' => $this->data($allocation)];
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
    private function data(FeePaymentAllocation $allocation): array
    {
        return ['id' => (string) $allocation->public_id, 'invoice_id' => (string) $allocation->invoice?->public_id, 'line_id' => (string) $allocation->line?->public_id, 'currency' => (string) $allocation->currency, 'payment_amount_minor_units' => (int) $allocation->payment_amount_minor_units, 'amount_minor_units' => (int) $allocation->amount_minor_units, 'status' => (string) $allocation->status, 'created_at' => $allocation->created_at?->toAtomString()];
    }

    /** @param array<string, mixed> $transition */
    private function record(UserIdentity $actor, string $action, string $subjectType, string $subjectId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: $subjectType, subjectPublicId: $subjectId, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
