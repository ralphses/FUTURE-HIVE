<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleSet;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'source_academic_term_id', 'target_academic_session_id', 'target_academic_term_id', 'academic_promotion_rule_set_id', 'status', 'created_by', 'approved_by', 'applied_by', 'rolled_back_by', 'approved_at', 'applied_at', 'cancelled_at', 'rolled_back_at', 'reason'])]
#[Hidden(['id', 'school_id', 'created_by', 'approved_by', 'applied_by', 'rolled_back_by', 'reason'])]
final class StudentPromotionCycle extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $cycle): void {
            $cycle->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'applied_at' => 'datetime', 'cancelled_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function sourceTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'source_academic_term_id');
    }

    /** @return BelongsTo<AcademicSession, $this> */
    public function targetSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'target_academic_session_id');
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function targetTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'target_academic_term_id');
    }

    /** @return BelongsTo<AcademicPromotionRuleSet, $this> */
    public function promotionRule(): BelongsTo
    {
        return $this->belongsTo(AcademicPromotionRuleSet::class, 'academic_promotion_rule_set_id');
    }

    /** @return HasMany<StudentPromotionDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(StudentPromotionDecision::class);
    }
}
