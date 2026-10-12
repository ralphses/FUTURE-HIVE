<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_assessment_instance_id', 'academic_term_id', 'academic_subject_offering_id', 'submitted_by', 'status', 'entry_count', 'submitted_at', 'request_id', 'reviewed_by', 'reviewed_at', 'return_reason', 'resubmitted_at', 'review_request_id'])]
#[Hidden(['id', 'school_id', 'academic_assessment_instance_id', 'academic_term_id', 'academic_subject_offering_id', 'submitted_by', 'request_id', 'reviewed_by', 'review_request_id'])]
final class AcademicAssessmentSubmission extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $submission): void {
            $submission->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['entry_count' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicAssessmentInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentInstance::class, 'academic_assessment_instance_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'submitted_by');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'reviewed_by');
    }
}
