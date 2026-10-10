<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_promotion_rule_set_id', 'metric', 'operator', 'threshold', 'is_required', 'sequence'])]
#[Hidden(['id'])]
final class AcademicPromotionRuleCriterion extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $criterion): void {
            $criterion->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['threshold' => 'decimal:2', 'is_required' => 'boolean', 'sequence' => 'integer'];
    }

    /** @return BelongsTo<AcademicPromotionRuleSet, $this> */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(AcademicPromotionRuleSet::class, 'academic_promotion_rule_set_id');
    }
}
