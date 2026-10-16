<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'counselling_case_id', 'student_id', 'student_enrollment_id', 'owner_assignment_id', 'owner_staff_profile_id', 'title', 'purpose', 'goals', 'action_items', 'review_date', 'progress_state', 'status', 'version', 'created_by', 'activated_at', 'closed_at', 'archived_at', 'transition_reason', 'active_plan_key', 'request_id'])]
#[Hidden(['id', 'school_id', 'counselling_case_id', 'student_id', 'student_enrollment_id', 'owner_assignment_id', 'owner_staff_profile_id', 'created_by', 'transition_reason', 'active_plan_key', 'request_id'])]
final class CounsellingDevelopmentPlan extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(fn (self $plan): string => $plan->public_id ??= (string) Str::uuid7());
    }

    /** @return BelongsTo<CounsellingCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CounsellingCase::class, 'counselling_case_id');
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

    /** @return BelongsTo<CounsellorAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CounsellorAssignment::class, 'owner_assignment_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'owner_staff_profile_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }

    /** @return HasMany<CounsellingDevelopmentPlanRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(CounsellingDevelopmentPlanRevision::class, 'development_plan_id');
    }

    /** @return HasMany<CounsellingDevelopmentPlanShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(CounsellingDevelopmentPlanShare::class, 'development_plan_id');
    }

    protected function casts(): array
    {
        return ['review_date' => 'date', 'activated_at' => 'datetime', 'closed_at' => 'datetime', 'archived_at' => 'datetime', 'request_id' => 'string'];
    }
}
