<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\TeachingAnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_class_arm_id', 'academic_subject_offering_id', 'author_id', 'title', 'body', 'status', 'submitted_at', 'submitted_by', 'approved_at', 'approved_by', 'published_at', 'published_by', 'recalled_at', 'recalled_by', 'rejected_at', 'rejected_by', 'review_reason', 'created_by', 'updated_by'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_class_arm_id', 'academic_subject_offering_id', 'submitted_by', 'approved_by', 'published_by', 'recalled_by', 'rejected_by', 'created_by', 'updated_by'])]
final class TeachingAnnouncement extends Model
{
    /** @use HasFactory<TeachingAnnouncementFactory> */
    use HasFactory, TenantScoped;

    protected static function newFactory(): TeachingAnnouncementFactory
    {
        return TeachingAnnouncementFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $announcement): void {
            $announcement->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'recalled_at' => 'datetime', 'rejected_at' => 'datetime'];
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

    /** @return BelongsTo<AcademicSubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicSubjectOffering::class, 'academic_subject_offering_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'author_id');
    }
}
