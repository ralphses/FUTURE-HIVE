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

#[Fillable(['public_id', 'school_id', 'attendance_register_id', 'attendance_entry_id', 'previous_status', 'previous_reason', 'replacement_status', 'replacement_reason', 'correction_reason', 'corrected_by', 'request_id', 'corrected_at'])]
#[Hidden(['id', 'school_id', 'attendance_register_id', 'attendance_entry_id', 'corrected_by', 'request_id'])]
final class AcademicAttendanceCorrection extends Model
{
    use TenantScoped;

    protected $table = 'attendance_corrections';

    protected static function booted(): void
    {
        self::creating(function (self $correction): void {
            $correction->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<AcademicAttendanceRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(AcademicAttendanceRegister::class, 'attendance_register_id');
    }

    /** @return BelongsTo<AcademicAttendanceEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(AcademicAttendanceEntry::class, 'attendance_entry_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'corrected_by');
    }

    protected function casts(): array
    {
        return ['corrected_at' => 'datetime'];
    }
}
