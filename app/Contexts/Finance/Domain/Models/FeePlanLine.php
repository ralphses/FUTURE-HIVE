<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_plan_version_id', 'fee_category_id', 'amount_minor_units', 'sequence', 'is_required'])]
#[Hidden(['id', 'school_id', 'fee_plan_version_id', 'fee_category_id'])]
final class FeePlanLine extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $line): void {
            $line->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer', 'sequence' => 'integer', 'is_required' => 'boolean'];
    }

    /** @return BelongsTo<FeePlanVersion, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FeePlanVersion::class, 'fee_plan_version_id');
    }

    /** @return BelongsTo<FeeCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }
}
