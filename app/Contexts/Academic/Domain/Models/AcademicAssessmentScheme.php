<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicAssessmentSchemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'name', 'total_marks'])]
#[Hidden(['id'])]
final class AcademicAssessmentScheme extends Model
{
    /** @use HasFactory<AcademicAssessmentSchemeFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicAssessmentSchemeFactory
    {
        return AcademicAssessmentSchemeFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $scheme): void {
            $scheme->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['total_marks' => 'integer'];
    }

    /** @return HasMany<AcademicAssessmentComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(AcademicAssessmentComponent::class, 'academic_assessment_scheme_id');
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
}
