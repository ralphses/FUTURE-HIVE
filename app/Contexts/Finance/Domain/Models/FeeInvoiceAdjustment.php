<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_invoice_id', 'fee_invoice_line_id', 'fee_adjustment_program_id', 'adjustment_type', 'calculation_method', 'base_minor_units', 'amount_minor_units', 'percentage_basis_points', 'status', 'reason', 'requested_by', 'approved_by', 'approved_at', 'applied_by', 'applied_at', 'rejected_by', 'rejected_at', 'cancelled_by', 'cancelled_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'fee_invoice_line_id', 'fee_adjustment_program_id', 'requested_by', 'approved_by', 'applied_by', 'rejected_by', 'cancelled_by', 'request_id'])]
final class FeeInvoiceAdjustment extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $adjustment): void {
            $adjustment->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['base_minor_units' => 'integer', 'amount_minor_units' => 'integer', 'percentage_basis_points' => 'integer', 'approved_at' => 'datetime', 'applied_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime'];
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

    /** @return BelongsTo<FeeAdjustmentProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(FeeAdjustmentProgram::class, 'fee_adjustment_program_id');
    }
}
