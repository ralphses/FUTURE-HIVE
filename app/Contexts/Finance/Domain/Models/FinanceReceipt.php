<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'receipt_number', 'receipt_sequence', 'fee_invoice_id', 'source_type', 'source_public_id', 'fee_payment_allocation_id', 'fee_payment_evidence_id', 'amount_minor_units', 'currency', 'issue_date', 'status', 'description', 'issued_by', 'request_id'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'fee_payment_allocation_id', 'fee_payment_evidence_id', 'issued_by', 'request_id'])]
final class FinanceReceipt extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $receipt): void {
            $receipt->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['receipt_sequence' => 'integer', 'amount_minor_units' => 'integer', 'issue_date' => 'date'];
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }

    /** @return BelongsTo<FeePaymentAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FeePaymentAllocation::class, 'fee_payment_allocation_id');
    }

    /** @return BelongsTo<FeePaymentEvidence, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(FeePaymentEvidence::class, 'fee_payment_evidence_id');
    }
}
