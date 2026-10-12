<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicAssessmentInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_policy_version_id', 'academic_grading_scale_version_id', 'title', 'assessment_date', 'instructions', 'status', 'created_by', 'opened_by', 'opened_at', 'closed_by', 'closed_at', 'cancelled_by', 'cancelled_at'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_policy_version_id', 'academic_grading_scale_version_id', 'created_by', 'opened_by', 'closed_by', 'cancelled_by'])]
final class AcademicAssessmentInstance extends Model
{
    /** @use HasFactory<AcademicAssessmentInstanceFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicAssessmentInstanceFactory
    {
        return AcademicAssessmentInstanceFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $instance): void {
            $instance->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['assessment_date' => 'date', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime'];
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

    /** @return BelongsTo<AcademicSubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicSubjectOffering::class, 'academic_subject_offering_id');
    }

    /** @return BelongsTo<AcademicAssessmentPolicyVersion, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentPolicyVersion::class, 'academic_assessment_policy_version_id');
    }

    /** @return BelongsTo<AcademicGradingScaleVersion, $this> */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(AcademicGradingScaleVersion::class, 'academic_grading_scale_version_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }
}
