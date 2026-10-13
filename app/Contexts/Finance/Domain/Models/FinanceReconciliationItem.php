<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'finance_reconciliation_run_id', 'school_id', 'source_type', 'source_public_id', 'settlement_reference_hash', 'amount_minor_units', 'currency', 'status', 'exception_code', 'resolution_reason', 'resolved_by', 'resolved_at'])]
#[Hidden(['id', 'finance_reconciliation_run_id', 'school_id', 'settlement_reference_hash', 'resolved_by'])]
final class FinanceReconciliationItem extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<FinanceReconciliationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(FinanceReconciliationRun::class, 'finance_reconciliation_run_id');
    }
}
