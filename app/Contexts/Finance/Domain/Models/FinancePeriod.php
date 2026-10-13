<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'status', 'started_by', 'closed_by', 'reopened_by', 'closing_started_at', 'closed_at', 'reopened_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'started_by', 'closed_by', 'reopened_by', 'request_id'])]
final class FinancePeriod extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $period): void {
            $period->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['closing_started_at' => 'datetime', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    /** @return HasMany<FinancePostCloseAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(FinancePostCloseAdjustment::class);
    }
}
