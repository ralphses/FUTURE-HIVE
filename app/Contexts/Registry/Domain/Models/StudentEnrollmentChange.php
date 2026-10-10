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

#[Fillable(['public_id', 'school_id', 'student_id', 'enrollment_id', 'replacement_enrollment_id', 'change_type', 'effective_date', 'reason', 'created_by'])]
#[Hidden(['id', 'school_id', 'created_by', 'reason'])]
final class StudentEnrollmentChange extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $change): void {
            $change->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'enrollment_id');
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function replacementEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'replacement_enrollment_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }
}
