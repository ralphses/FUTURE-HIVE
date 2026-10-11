<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_class_arm_id', 'attendance_date', 'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'submitted_request_id', 'locked_by', 'locked_at', 'locked_request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_class_arm_id', 'created_by', 'updated_by', 'submitted_by', 'submitted_request_id', 'locked_by', 'locked_request_id'])]
final class AcademicAttendanceRegister extends Model
{
    use TenantScoped;

    protected $table = 'attendance_registers';

    protected static function booted(): void
    {
        self::creating(function (self $register): void {
            $register->public_id ??= (string) Str::uuid7();
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

    /** @return HasMany<AcademicAttendanceEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AcademicAttendanceEntry::class, 'attendance_register_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'submitted_at' => 'datetime', 'locked_at' => 'datetime'];
    }
}
