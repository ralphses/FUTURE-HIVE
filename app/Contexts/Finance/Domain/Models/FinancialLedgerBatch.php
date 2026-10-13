<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'currency', 'source_type', 'source_public_id', 'source_fingerprint', 'effective_date', 'posting_date', 'status', 'description', 'posted_by', 'posted_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'source_fingerprint', 'posted_by', 'request_id'])]
final class FinancialLedgerBatch extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $batch): void {
            $batch->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'posting_date' => 'date', 'posted_at' => 'datetime'];
    }

    /** @return HasMany<FinancialLedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class)->orderBy('id');
    }
}
