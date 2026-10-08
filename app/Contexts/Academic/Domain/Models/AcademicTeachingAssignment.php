<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicTeachingAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'teacher_id', 'effective_start', 'effective_end', 'status', 'assigned_by', 'assigned_at', 'revoked_by', 'revoked_at', 'reason', 'revoked_reason'])]
#[Hidden(['id', 'assigned_by', 'revoked_by'])]
final class AcademicTeachingAssignment extends Model
{
    /** @use HasFactory<AcademicTeachingAssignmentFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicTeachingAssignmentFactory
    {
        return AcademicTeachingAssignmentFactory::new();
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

    /** @return BelongsTo<AcademicSubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicSubjectOffering::class, 'academic_subject_offering_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'teacher_id');
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
