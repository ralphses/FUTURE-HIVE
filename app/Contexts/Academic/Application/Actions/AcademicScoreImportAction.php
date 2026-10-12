<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreEntry;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Contracts\ImportSpecializedCommitter;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class AcademicScoreImportAction implements ImportSpecializedCommitter
{
    public function __construct(private readonly AcademicAssessmentScoreAction $scores) {}

    /** @return array{status: string, action: string, id: string|null, error: string|null} */
    public function commitRow(Authenticatable $actor, ImportBatch $batch, ImportStagedRow $row): array
    {
        if (! $actor instanceof UserIdentity) {
            throw (new ModelNotFoundException)->setModel(ImportBatch::class, [$batch->getKey()]);
        }

        $instance = AcademicAssessmentInstance::query()->whereKey($batch->academic_assessment_instance_id)->with(['session', 'term', 'offering', 'policy.components'])->first();
        if (! $instance instanceof AcademicAssessmentInstance || $instance->status !== 'open') {
            throw new ModelNotFoundException;
        }
        $school = TenantContext::require()->schoolPublicId;
        $this->scores->authorizeImport($actor, $school, (string) $instance->session?->public_id, (string) $instance->term?->public_id, (string) $instance->offering?->public_id, (string) $instance->public_id);
        $values = $row->normalized_cells ?? [];
        $student = Student::query()->where('school_id', TenantContext::require()->schoolId)->where('student_number', (string) ($values['student_number'] ?? ''))->first();
        $component = $instance->policy?->components()->where('sequence', (int) ($values['component_sequence'] ?? 0))->first();
        if (! $student instanceof Student || $component === null) {
            throw new ModelNotFoundException;
        }
        $enrolled = StudentEnrollment::query()->where('school_id', TenantContext::require()->schoolId)->where('student_id', $student->getKey())->where('academic_term_id', $instance->academic_term_id)->where('academic_class_arm_id', $instance->offering?->academic_class_arm_id)->where('status', 'active')->whereDate('start_date', '<=', $instance->assessment_date)->where(function ($query) use ($instance): void {
            $query->whereNull('end_date')->orWhereDate('end_date', '>=', $instance->assessment_date);
        })->exists();
        if (! $enrolled) {
            throw new ModelNotFoundException;
        }
        $existing = AcademicAssessmentScoreEntry::query()->where('academic_assessment_instance_id', $instance->getKey())->where('student_id', $student->getKey())->where('academic_assessment_policy_component_id', $component->getKey())->lockForUpdate()->first();
        if ($existing instanceof AcademicAssessmentScoreEntry && $existing->status !== 'missing') {
            throw ValidationException::withMessages(['score_conflict' => ['An existing non-missing score must not be overwritten by an import.']]);
        }
        $result = $this->scores->update($actor, $school, (string) $instance->session?->public_id, (string) $instance->term?->public_id, (string) $instance->offering?->public_id, (string) $instance->public_id, ['entries' => [[
            'student_id' => (string) $student->public_id,
            'component_id' => (string) $component->public_id,
            'status' => (string) ($values['status'] ?? 'missing'),
            'score' => $values['score'] ?? null,
            'expected_version' => (int) ($existing->revision ?? 0),
        ]]], 'import');

        return ['status' => 'completed', 'action' => 'score_upsert', 'id' => data_get($result, 'items.0.id'), 'error' => null];
    }
}
