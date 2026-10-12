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

#[Fillable(['public_id', 'school_id', 'academic_assessment_instance_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_score_entry_id', 'student_id', 'academic_assessment_policy_component_id', 'previous_status', 'previous_score', 'replacement_status', 'replacement_score', 'revision_before', 'revision_after', 'actor_id', 'mutation_source', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_assessment_instance_id', 'academic_term_id', 'academic_subject_offering_id', 'academic_assessment_score_entry_id', 'student_id', 'academic_assessment_policy_component_id', 'actor_id', 'request_id'])]
final class AcademicAssessmentScoreAudit extends Model
{
    use TenantScoped;

    public $timestamps = false;

    protected static function booted(): void
    {
        self::creating(function (self $audit): void {
            $audit->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['previous_score' => 'decimal:2', 'replacement_score' => 'decimal:2', 'revision_before' => 'integer', 'revision_after' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicAssessmentScoreEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentScoreEntry::class, 'academic_assessment_score_entry_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'actor_id');
    }
}
