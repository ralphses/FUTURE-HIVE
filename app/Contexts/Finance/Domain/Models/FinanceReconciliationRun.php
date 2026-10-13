<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'source_fingerprint', 'status', 'description', 'created_by', 'cancelled_by', 'started_at', 'completed_at', 'cancelled_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'source_fingerprint', 'created_by', 'cancelled_by', 'request_id'])]
final class FinanceReconciliationRun extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return HasMany<FinanceReconciliationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FinanceReconciliationItem::class)->orderBy('id');
    }
}
