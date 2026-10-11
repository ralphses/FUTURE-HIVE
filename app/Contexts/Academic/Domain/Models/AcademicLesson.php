<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\Contexts\Academic\Domain\Models\AcademicLessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_class_arm_id', 'academic_subject_offering_id', 'academic_teaching_assignment_id', 'teacher_id', 'academic_scheme_topic_id', 'lesson_date', 'teaching_week', 'title', 'objectives', 'materials', 'activities', 'evaluation', 'assignment', 'status', 'submitted_snapshot', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_reason', 'created_by', 'updated_by'])]
#[Hidden(['id', 'created_by', 'updated_by', 'reviewed_by'])]
final class AcademicLesson extends Model
{
    /** @use HasFactory<AcademicLessonFactory> */
    use HasFactory, TenantScoped;

    protected static function newFactory(): AcademicLessonFactory
    {
        return AcademicLessonFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $lesson): void {
            $lesson->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['lesson_date' => 'date', 'teaching_week' => 'integer', 'submitted_snapshot' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicSubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicSubjectOffering::class, 'academic_subject_offering_id');
    }

    /** @return BelongsTo<AcademicTeachingAssignment, $this> */
    public function teachingAssignment(): BelongsTo
    {
        return $this->belongsTo(AcademicTeachingAssignment::class, 'academic_teaching_assignment_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'teacher_id');
    }

    /** @return BelongsTo<AcademicSchemeTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(AcademicSchemeTopic::class, 'academic_scheme_topic_id');
    }
}
