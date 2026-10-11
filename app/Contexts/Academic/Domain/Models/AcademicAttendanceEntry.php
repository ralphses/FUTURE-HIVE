<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'attendance_register_id', 'student_id', 'status', 'reason', 'recorded_by', 'recorded_at'])]
#[Hidden(['id', 'school_id', 'attendance_register_id', 'student_id', 'recorded_by'])]
final class AcademicAttendanceEntry extends Model
{
    use TenantScoped;

    protected $table = 'attendance_entries';

    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            $entry->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<AcademicAttendanceRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(AcademicAttendanceRegister::class, 'attendance_register_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
