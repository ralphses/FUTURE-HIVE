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

#[Fillable(['public_id', 'school_id', 'counsellor_assignment_id', 'staff_profile_id', 'student_id', 'student_enrollment_id', 'title', 'category', 'status', 'opened_by', 'opened_at', 'hold_at', 'closed_at', 'archived_at', 'transition_reason', 'active_case_key', 'request_id'])]
#[Hidden(['id', 'school_id', 'counsellor_assignment_id', 'staff_profile_id', 'student_id', 'student_enrollment_id', 'opened_by', 'transition_reason', 'active_case_key', 'request_id'])]
final class CounsellingCase extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $case): void {
            $case->public_id ??= (string) Str::uuid7();
        });
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
    public function opener(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'opened_by');
    }

    /** @return HasMany<CounsellingCaseNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CounsellingCaseNote::class, 'counselling_case_id');
    }

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'hold_at' => 'datetime', 'closed_at' => 'datetime', 'archived_at' => 'datetime', 'request_id' => 'string'];
    }
}
