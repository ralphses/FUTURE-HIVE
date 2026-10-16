<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'counselling_case_id', 'counsellor_assignment_id', 'staff_profile_id', 'student_id', 'student_enrollment_id', 'type', 'status', 'title', 'purpose', 'urgency', 'reason_code', 'destination_scope', 'requested_by', 'reviewed_by', 'accepted_by', 'requested_at', 'reviewed_at', 'approved_at', 'rejected_at', 'accepted_at', 'closed_at', 'cancelled_at', 'transition_reason', 'active_referral_key', 'request_id'])]
#[Hidden(['id', 'school_id', 'counselling_case_id', 'counsellor_assignment_id', 'staff_profile_id', 'student_id', 'student_enrollment_id', 'requested_by', 'reviewed_by', 'accepted_by', 'transition_reason', 'active_referral_key', 'request_id'])]
final class CounsellingReferral extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(fn (self $referral): string => $referral->public_id ??= (string) Str::uuid7());
    }

    /** @return BelongsTo<CounsellingCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CounsellingCase::class, 'counselling_case_id');
    }

    /** @return BelongsTo<CounsellorAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CounsellorAssignment::class, 'counsellor_assignment_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
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

    /** @return BelongsTo<UserIdentity, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'requested_by');
    }

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'accepted_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime', 'request_id' => 'string'];
    }
}
