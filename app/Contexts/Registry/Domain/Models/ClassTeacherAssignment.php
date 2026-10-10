<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\ClassTeacherAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_class_arm_id', 'school_membership_id', 'staff_profile_id', 'effective_start', 'effective_end', 'status', 'assigned_by', 'assigned_at', 'revoked_by', 'revoked_at', 'reason', 'revoked_reason'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_class_arm_id', 'school_membership_id', 'staff_profile_id', 'assigned_by', 'revoked_by', 'reason', 'revoked_reason'])]
final class ClassTeacherAssignment extends Model
{
    /** @use HasFactory<ClassTeacherAssignmentFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): ClassTeacherAssignmentFactory
    {
        return ClassTeacherAssignmentFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $assignment): void {
            $assignment->public_id ??= (string) Str::uuid7();
        });
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

    /** @return BelongsTo<AcademicClassArm, $this> */
    public function classArm(): BelongsTo
    {
        return $this->belongsTo(AcademicClassArm::class, 'academic_class_arm_id');
    }

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    protected function casts(): array
    {
        return [
            'effective_start' => 'date',
            'effective_end' => 'date',
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
