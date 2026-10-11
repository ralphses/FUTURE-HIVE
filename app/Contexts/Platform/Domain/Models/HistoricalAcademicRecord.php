<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'historical_academic_import_id', 'school_id', 'student_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_policy_version_id', 'row_number', 'score', 'max_marks', 'grade', 'remark', 'provenance_reference', 'status', 'error_code'])]
#[Hidden(['id', 'historical_academic_import_id', 'school_id', 'student_id', 'academic_session_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_policy_version_id'])]
final class HistoricalAcademicRecord extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $record): void {
            $record->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'score' => 'decimal:2', 'max_marks' => 'decimal:2'];
    }

    /** @return BelongsTo<HistoricalAcademicImport, $this> */
    public function historicalImport(): BelongsTo
    {
        return $this->belongsTo(HistoricalAcademicImport::class, 'historical_academic_import_id');
    }
}
