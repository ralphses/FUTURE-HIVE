<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'student_promotion_cycle_id', 'student_id', 'source_enrollment_id', 'target_academic_level_id', 'target_academic_class_arm_id', 'decision', 'status', 'reason', 'created_by', 'approved_by', 'applied_by', 'rolled_back_by', 'approved_at', 'applied_at', 'rolled_back_at'])]
#[Hidden(['id', 'school_id', 'created_by', 'approved_by', 'applied_by', 'rolled_back_by', 'reason'])]
final class StudentPromotionDecision extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $decision): void {
            $decision->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'applied_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }

    /** @return BelongsTo<StudentPromotionCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(StudentPromotionCycle::class, 'student_promotion_cycle_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function sourceEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'source_enrollment_id');
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function targetLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'target_academic_level_id');
    }

    /** @return BelongsTo<AcademicClassArm, $this> */
    public function targetClassArm(): BelongsTo
    {
        return $this->belongsTo(AcademicClassArm::class, 'target_academic_class_arm_id');
    }
}
