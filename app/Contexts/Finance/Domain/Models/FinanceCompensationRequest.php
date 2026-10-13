<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'kind', 'source_type', 'source_public_id', 'fee_invoice_id', 'currency', 'amount_minor_units', 'reason', 'status', 'requested_by', 'approved_by', 'rejected_by', 'applied_by', 'requested_at', 'approved_at', 'rejected_at', 'applied_at', 'request_id', 'ledger_batch_public_id'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'requested_by', 'approved_by', 'rejected_by', 'applied_by', 'request_id'])]
final class FinanceCompensationRequest extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $request): void {
            $request->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer', 'requested_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
