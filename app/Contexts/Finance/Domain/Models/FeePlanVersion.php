<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_level_id', 'academic_section_id', 'academic_class_arm_id', 'version', 'name', 'currency', 'effective_from', 'effective_to', 'status', 'created_by', 'activated_by', 'activated_at', 'retired_by', 'retired_at'])]
#[Hidden(['id', 'school_id', 'created_by', 'activated_by', 'retired_by'])]
final class FeePlanVersion extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $plan): void {
            $plan->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** @return HasMany<FeePlanLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FeePlanLine::class)->orderBy('sequence');
    }

    /** @return BelongsTo<AcademicSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<AcademicSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class, 'academic_section_id');
    }

    /** @return BelongsTo<AcademicClassArm, $this> */
    public function classArm(): BelongsTo
    {
        return $this->belongsTo(AcademicClassArm::class, 'academic_class_arm_id');
    }
}
