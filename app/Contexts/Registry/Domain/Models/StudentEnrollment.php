<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'student_id', 'academic_session_id', 'academic_term_id', 'academic_level_id', 'academic_section_id', 'academic_class_arm_id', 'start_date', 'end_date', 'status', 'created_by', 'ended_by', 'end_reason'])]
#[Hidden(['id', 'school_id', 'created_by', 'ended_by', 'end_reason'])]
final class StudentEnrollment extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $enrollment): void {
            $enrollment->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<AcademicSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class, 'academic_section_id');
    }

    /** @return BelongsTo<AcademicClassArm, $this> */
    public function classArm(): BelongsTo
    {
        return $this->belongsTo(AcademicClassArm::class, 'academic_class_arm_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function ender(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'ended_by');
    }
}
