<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'finance_period_id', 'source_type', 'source_public_id', 'amount_minor_units', 'currency', 'reason', 'status', 'requested_by', 'approved_by', 'applied_by', 'requested_at', 'approved_at', 'rejected_at', 'applied_at', 'rejection_reason', 'compensating_ledger_batch_public_id', 'request_id'])]
#[Hidden(['id', 'school_id', 'finance_period_id', 'requested_by', 'approved_by', 'applied_by', 'request_id'])]
final class FinancePostCloseAdjustment extends Model
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
        return ['amount_minor_units' => 'integer', 'requested_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<FinancePeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancePeriod::class, 'finance_period_id');
    }
}
