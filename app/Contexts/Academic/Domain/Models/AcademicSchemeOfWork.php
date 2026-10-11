<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\Contexts\Academic\Domain\Models\AcademicSchemeOfWorkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_level_id', 'academic_section_id', 'academic_class_arm_id', 'academic_subject_offering_id', 'status', 'created_by', 'updated_by'])]
#[Hidden(['id', 'created_by', 'updated_by'])]
final class AcademicSchemeOfWork extends Model
{
    /** @use HasFactory<AcademicSchemeOfWorkFactory> */
    use HasFactory, TenantScoped;

    protected $table = 'academic_schemes_of_work';

    protected static function newFactory(): AcademicSchemeOfWorkFactory
    {
        return AcademicSchemeOfWorkFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $scheme): void {
            $scheme->public_id ??= (string) Str::uuid7();
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

    /** @return HasMany<AcademicSchemeTopic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(AcademicSchemeTopic::class, 'academic_scheme_of_work_id');
    }
}
