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

#[Fillable(['public_id', 'school_id', 'academic_assessment_instance_id', 'academic_term_id', 'student_id', 'academic_assessment_policy_component_id', 'score', 'status', 'revision', 'updated_by'])]
#[Hidden(['id', 'school_id', 'updated_by'])]
final class AcademicAssessmentScoreEntry extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            $entry->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'revision' => 'integer'];
    }

    /** @return BelongsTo<AcademicAssessmentInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentInstance::class, 'academic_assessment_instance_id');
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AcademicAssessmentPolicyComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(AcademicAssessmentPolicyComponent::class, 'academic_assessment_policy_component_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'updated_by');
    }
}
