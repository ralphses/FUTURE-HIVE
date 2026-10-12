<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_result_run_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'student_id', 'status', 'total_score', 'total_max_marks', 'percentage', 'grade', 'grade_label', 'is_passing', 'remark', 'error_code'])]
#[Hidden(['id', 'school_id', 'academic_result_run_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'student_id'])]
final class AcademicResultEntry extends Model
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

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AcademicResultRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AcademicResultRun::class, 'academic_result_run_id');
    }

    /** @return BelongsTo<AcademicSubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicSubjectOffering::class, 'academic_subject_offering_id');
    }
}
