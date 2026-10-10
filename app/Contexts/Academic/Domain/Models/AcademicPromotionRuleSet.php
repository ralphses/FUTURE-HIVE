<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'source_academic_level_id', 'target_academic_level_id', 'name', 'description', 'status'])]
#[Hidden(['id'])]
final class AcademicPromotionRuleSet extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $rule): void {
            $rule->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<AcademicPromotionRuleCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(AcademicPromotionRuleCriterion::class)->orderBy('sequence');
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function sourceLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'source_academic_level_id');
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function targetLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'target_academic_level_id');
    }
}
