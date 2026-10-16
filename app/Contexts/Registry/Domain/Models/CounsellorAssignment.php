<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'staff_profile_id', 'school_membership_id', 'student_id', 'student_enrollment_id', 'status', 'active_assignment_key', 'assigned_by', 'assigned_at', 'revoked_by', 'revoked_at', 'reason', 'revoked_reason', 'request_id'])]
#[Hidden(['id', 'school_id', 'staff_profile_id', 'school_membership_id', 'student_id', 'student_enrollment_id', 'assigned_by', 'revoked_by', 'reason', 'revoked_reason', 'request_id', 'active_assignment_key'])]
final class CounsellorAssignment extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $assignment): void {
            $assignment->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
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

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'request_id' => 'string',
        ];
    }
}
