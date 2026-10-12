<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyComponent;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreAudit;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreEntry;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicAssessmentScoreAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly AcademicAssessmentAccessWindowAction $accessWindow,
    ) {}

    /** @return array<string, mixed> */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance, 'academic.scores.read');
        $this->assertActorScope($actor, $school, $subjectOffering, $assessmentInstance, false);

        $assessmentDate = CarbonImmutable::parse((string) $assessmentInstance->assessment_date);
        $enrollments = $this->roster($academicTerm, $subjectOffering, $assessmentDate);
        $components = $assessmentInstance->policy->components()->orderBy('sequence')->orderBy('id')->get();
        $existing = AcademicAssessmentScoreEntry::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->get()->keyBy(fn (AcademicAssessmentScoreEntry $entry): string => $entry->student_id.':'.$entry->academic_assessment_policy_component_id);

        return ['items' => $enrollments->map(function (StudentEnrollment $enrollment) use ($components, $existing): array {
            return [
                'student_id' => (string) $enrollment->student?->public_id,
                'student_name' => (string) $enrollment->student?->display_name,
                'entries' => $components->map(function (AcademicAssessmentPolicyComponent $component) use ($enrollment, $existing): array {
                    $entry = $existing->get($enrollment->student_id.':'.$component->getKey());

                    return [
                        'id' => $entry instanceof AcademicAssessmentScoreEntry ? (string) $entry->public_id : null,
                        'component_id' => (string) $component->public_id,
                        'component_name' => (string) $component->name,
                        'max_marks' => (int) $component->max_marks,
                        'status' => $entry instanceof AcademicAssessmentScoreEntry ? (string) $entry->status : 'missing',
                        'score' => $entry instanceof AcademicAssessmentScoreEntry && $entry->score !== null ? (float) $entry->score : null,
                        'version' => $entry instanceof AcademicAssessmentScoreEntry ? (int) $entry->revision : 0,
                    ];
                })->values()->all(),
            ];
        })->values()->all()];
    }

    /** @param array{entries: list<array{student_id: string, component_id: string, status: string, score?: float|int|string|null, expected_version: int}>} $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, array $input, string $mutationSource = 'manual'): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance, 'academic.scores.manage');
        $this->assertActorScope($actor, $school, $subjectOffering, $assessmentInstance, true);

        return DB::transaction(function () use ($actor, $assessmentInstance, $academicTerm, $subjectOffering, $input, $mutationSource): array {
            $assessmentInstance = AcademicAssessmentInstance::query()->whereKey($assessmentInstance->getKey())->lockForUpdate()->firstOrFail();
            if ($assessmentInstance->status !== 'open') {
                throw ValidationException::withMessages(['assessment_instance' => ['Scores can only be changed while the assessment instance is open.']]);
            }
            $this->accessWindow->assertWritable($assessmentInstance);
            $submission = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->first();
            if ($submission instanceof AcademicAssessmentSubmission && $submission->status !== 'returned') {
                throw ValidationException::withMessages(['assessment_instance' => ['Submitted scores can no longer be edited.']]);
            }
            $assessmentDate = CarbonImmutable::parse((string) $assessmentInstance->assessment_date);
            $roster = $this->roster($academicTerm, $subjectOffering, $assessmentDate)->keyBy('student_id');
            $components = $assessmentInstance->policy->components()->get()->keyBy('public_id');
            $items = [];

            foreach ($input['entries'] as $payload) {
                $student = Student::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', (string) $payload['student_id'])->first();
                $component = $components->get((string) $payload['component_id']);
                if (! $student instanceof Student || ! $component instanceof AcademicAssessmentPolicyComponent || ! $roster->has($student->getKey())) {
                    throw new ModelNotFoundException;
                }
                $status = (string) $payload['status'];
                $score = $payload['score'] ?? null;
                $normalizedScore = null;
                if ($status === 'entered') {
                    if ($score === null) {
                        throw ValidationException::withMessages(['entries' => ['Entered scores must be present and must not exceed the component maximum.']]);
                    }
                    $normalizedScore = $this->normalizeScore($score, (string) $component->max_marks);
                } elseif ($score !== null) {
                    throw ValidationException::withMessages(['entries' => ['Missing, absent and exempt entries cannot contain a numeric score.']]);
                }
                $entry = AcademicAssessmentScoreEntry::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->where('student_id', $student->getKey())->where('academic_assessment_policy_component_id', $component->getKey())->lockForUpdate()->first();
                if (! $entry instanceof AcademicAssessmentScoreEntry) {
                    if ((int) $payload['expected_version'] !== 0) {
                        throw ValidationException::withMessages(['expected_version' => ['The score entry version is stale. Refresh the score entry and retry.']]);
                    }
                    $entry = AcademicAssessmentScoreEntry::query()->create(['school_id' => TenantContext::require()->schoolId, 'academic_assessment_instance_id' => $assessmentInstance->getKey(), 'academic_term_id' => $academicTerm->getKey(), 'student_id' => $student->getKey(), 'academic_assessment_policy_component_id' => $component->getKey(), 'score' => $normalizedScore, 'status' => $status, 'revision' => 1, 'updated_by' => $actor->getKey()]);
                    $this->recordScoreAudit($actor, $entry, null, null, $status, $normalizedScore, 0, 1, $mutationSource, $subjectOffering);
                } else {
                    $expectedVersion = (int) $payload['expected_version'];
                    $sameValue = (string) $entry->status === $status && (string) ($entry->score ?? '') === (string) ($normalizedScore ?? '');
                    if ($sameValue && in_array($expectedVersion, [(int) $entry->revision, max(0, (int) $entry->revision - 1)], true)) {
                        $items[] = $this->data($entry->load(['student', 'component']));

                        continue;
                    }
                    if ($expectedVersion !== (int) $entry->revision) {
                        throw ValidationException::withMessages(['expected_version' => ['The score entry version is stale. Refresh the score entry and retry.']]);
                    }
                    $previousStatus = (string) $entry->status;
                    $previousScore = $entry->score === null ? null : (string) $entry->score;
                    $nextRevision = (int) $entry->revision + 1;
                    $entry->update(['score' => $normalizedScore, 'status' => $status, 'revision' => $nextRevision, 'updated_by' => $actor->getKey()]);
                    $this->recordScoreAudit($actor, $entry, $previousStatus, $previousScore, $status, $normalizedScore, $nextRevision - 1, $nextRevision, $mutationSource, $subjectOffering);
                }
                $this->audit($actor, (string) $entry->public_id, ['status' => $status, 'student_id' => (string) $student->public_id, 'component_id' => (string) $component->public_id]);
                $items[] = $this->data($entry->load(['student', 'component']));
            }

            return ['items' => $items];
        });
    }

    public function authorizeImport(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): AcademicAssessmentInstance
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance, 'academic.scores.manage');
        $this->assertActorScope($actor, $school, $subjectOffering, $assessmentInstance, true);
        if ($assessmentInstance->status !== 'open') {
            throw new ModelNotFoundException;
        }
        $this->accessWindow->assertWritable($assessmentInstance);
        $submission = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->first();
        if ($submission instanceof AcademicAssessmentSubmission && $submission->status !== 'returned') {
            throw new ModelNotFoundException;
        }

        return $assessmentInstance;
    }

    private function normalizeScore(float|int|string $score, string $maximum): string
    {
        try {
            $normalized = BigDecimal::of((string) $score)->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            throw ValidationException::withMessages(['entries' => ['Entered scores must be valid decimal numbers.']]);
        }
        if ($normalized->compareTo($maximum) > 0) {
            throw ValidationException::withMessages(['entries' => ['Entered scores must be present and must not exceed the component maximum after rounding.']]);
        }

        return $normalized->toString();
    }

    /** @return array{0: AcademicAssessmentInstance, 1: AcademicTerm, 2: AcademicSubjectOffering} */
    private function authorizeInstance(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        $subjectOffering = AcademicSubjectOffering::query()->where('school_id', $context->schoolId)->where('public_id', $offering)->where('academic_term_id', $academicTerm?->getKey())->first();
        $assessmentInstance = AcademicAssessmentInstance::query()->where('school_id', $context->schoolId)->where('public_id', $instance)->where('academic_term_id', $academicTerm?->getKey())->where('academic_subject_offering_id', $subjectOffering?->getKey())->with(['policy.components'])->first();
        if (! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering || ! $assessmentInstance instanceof AcademicAssessmentInstance) {
            throw new ModelNotFoundException;
        }

        return [$assessmentInstance, $academicTerm, $subjectOffering];
    }

    private function assertActorScope(UserIdentity $actor, string $school, AcademicSubjectOffering $offering, AcademicAssessmentInstance $instance, bool $manage): void
    {
        $context = TenantContext::require();
        $leadership = SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer'])))->exists();
        if ($leadership) {
            return;
        }
        $date = CarbonImmutable::parse((string) $instance->assessment_date);
        $assigned = AcademicTeachingAssignment::query()->where('school_id', $context->schoolId)->where('academic_term_id', $offering->academic_term_id)->where('academic_subject_offering_id', $offering->getKey())->where('teacher_id', $actor->getKey())->where('status', 'active')->whereDate('effective_start', '<=', $date->toDateString())->where(function ($query) use ($date): void {
            $query->whereNull('effective_end')->orWhereDate('effective_end', '>=', $date->toDateString());
        })->exists();
        if (! $assigned) {
            throw new ModelNotFoundException;
        }
    }

    /** @return Collection<int, StudentEnrollment> */
    private function roster(AcademicTerm $term, AcademicSubjectOffering $offering, CarbonImmutable $date): Collection
    {
        return StudentEnrollment::query()->where('school_id', TenantContext::require()->schoolId)->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $offering->academic_class_arm_id)->where('status', 'active')->whereDate('start_date', '<=', $date->toDateString())->where(function ($query) use ($date): void {
            $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString());
        })->with('student')->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function data(AcademicAssessmentScoreEntry $entry): array
    {
        return ['id' => (string) $entry->public_id, 'student_id' => (string) $entry->student?->public_id, 'component_id' => (string) $entry->component?->public_id, 'status' => (string) $entry->status, 'score' => $entry->score === null ? null : (float) $entry->score, 'version' => (int) $entry->revision, 'updated_at' => $entry->updated_at === null ? null : CarbonImmutable::parse((string) $entry->updated_at)->toISOString()];
    }

    private function recordScoreAudit(UserIdentity $actor, AcademicAssessmentScoreEntry $entry, ?string $previousStatus, ?string $previousScore, string $replacementStatus, ?string $replacementScore, int $revisionBefore, int $revisionAfter, string $mutationSource, AcademicSubjectOffering $offering): void
    {
        AcademicAssessmentScoreAudit::query()->create([
            'school_id' => TenantContext::require()->schoolId,
            'academic_assessment_instance_id' => $entry->academic_assessment_instance_id,
            'academic_term_id' => $entry->academic_term_id,
            'academic_subject_offering_id' => $offering->getKey(),
            'academic_assessment_score_entry_id' => $entry->getKey(),
            'student_id' => $entry->student_id,
            'academic_assessment_policy_component_id' => $entry->academic_assessment_policy_component_id,
            'previous_status' => $previousStatus,
            'previous_score' => $previousScore,
            'replacement_status' => $replacementStatus,
            'replacement_score' => $replacementScore,
            'revision_before' => $revisionBefore,
            'revision_after' => $revisionAfter,
            'actor_id' => $actor->getKey(),
            'mutation_source' => $mutationSource,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /** @param array<string, mixed> $transition */
    private function audit(UserIdentity $actor, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: 'academic.assessment_score_saved', subjectType: 'academic_assessment_score_entry', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.scores.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }
}
