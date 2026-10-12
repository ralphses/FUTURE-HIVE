<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_result_snapshot_id', 'academic_result_entry_id', 'student_id', 'student_enrollment_id', 'academic_subject_offering_id', 'academic_assessment_policy_version_id', 'academic_grading_scale_version_id', 'status', 'total_score', 'total_max_marks', 'percentage', 'grade', 'grade_label', 'is_passing', 'remark', 'error_code'])]
#[Hidden(['id', 'school_id', 'academic_result_snapshot_id', 'academic_result_entry_id', 'student_id', 'student_enrollment_id', 'academic_subject_offering_id', 'academic_assessment_policy_version_id', 'academic_grading_scale_version_id'])]
final class AcademicResultSnapshotEntry extends Model
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
        return ['total_score' => 'decimal:2', 'total_max_marks' => 'decimal:2', 'percentage' => 'decimal:2', 'is_passing' => 'boolean'];
    }

    /** @return BelongsTo<AcademicResultSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AcademicResultSnapshot::class, 'academic_result_snapshot_id');
    }

    /** @return BelongsTo<AcademicResultEntry, $this> */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(AcademicResultEntry::class, 'academic_result_entry_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    /** @return BelongsTo<AcademicSubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicSubjectOffering::class, 'academic_subject_offering_id');
    }

    /** @return BelongsTo<AcademicAssessmentPolicyVersion, $this> */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentPolicyVersion::class, 'academic_assessment_policy_version_id');
    }

    /** @return BelongsTo<AcademicGradingScaleVersion, $this> */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(AcademicGradingScaleVersion::class, 'academic_grading_scale_version_id');
    }
}
