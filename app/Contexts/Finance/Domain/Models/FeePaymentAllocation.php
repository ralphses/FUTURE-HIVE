<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_invoice_id', 'fee_invoice_line_id', 'payment_reference', 'allocation_scope_key', 'currency', 'payment_amount_minor_units', 'amount_minor_units', 'status', 'allocated_by', 'request_id'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'fee_invoice_line_id', 'allocated_by', 'request_id', 'payment_reference', 'allocation_scope_key'])]
final class FeePaymentAllocation extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $allocation): void {
            $allocation->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['payment_amount_minor_units' => 'integer', 'amount_minor_units' => 'integer'];
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }

    /** @return BelongsTo<FeeInvoiceLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(FeeInvoiceLine::class, 'fee_invoice_line_id');
    }
}
