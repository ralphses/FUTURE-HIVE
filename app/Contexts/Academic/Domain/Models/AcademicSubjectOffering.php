<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicSubjectOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['school_id', 'academic_session_id', 'academic_term_id', 'academic_level_id', 'academic_section_id', 'academic_class_arm_id', 'academic_subject_id', 'display_order', 'status', 'public_id'])]
#[Hidden(['id'])]
final class AcademicSubjectOffering extends Model
{
    /** @use HasFactory<AcademicSubjectOfferingFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicSubjectOfferingFactory
    {
        return AcademicSubjectOfferingFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $offering): void {
            $offering->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['display_order' => 'integer'];
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

    /** @return BelongsTo<AcademicSubject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(AcademicSubject::class, 'academic_subject_id');
    }
}
