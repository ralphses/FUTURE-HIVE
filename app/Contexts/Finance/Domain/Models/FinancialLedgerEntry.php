<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'financial_ledger_batch_id', 'account_code', 'direction', 'amount_minor_units', 'currency', 'description'])]
#[Hidden(['id', 'school_id', 'financial_ledger_batch_id'])]
final class FinancialLedgerEntry extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            $entry->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer'];
    }

    /** @return BelongsTo<FinancialLedgerBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinancialLedgerBatch::class, 'financial_ledger_batch_id');
    }
}
