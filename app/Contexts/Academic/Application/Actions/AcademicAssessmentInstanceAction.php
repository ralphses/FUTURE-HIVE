<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyVersion;
use App\Contexts\Academic\Domain\Models\AcademicGradingScaleVersion;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicAssessmentInstanceAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly AcademicReadinessAction $readiness,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessments.read');

        return ['items' => AcademicAssessmentInstance::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_subject_offering_id', $subjectOffering->getKey())->with(['policy', 'gradingScale'])->orderByDesc('assessment_date')->orderByDesc('id')->get()->map(fn (AcademicAssessmentInstance $instance): array => $this->data($instance))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessments.read');

        return $this->data($this->find($instance, $academicTerm, $subjectOffering));
    }

    /** @param array{policy_id: string, grading_scale_id: string, title: string, assessment_date: string, instructions?: string|null} $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $session, string $term, string $offering, array $input): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessments.manage');

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $input): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $subjectOffering = AcademicSubjectOffering::query()->whereKey($subjectOffering->getKey())->lockForUpdate()->firstOrFail();
            $this->assertParentsOpen($academicTerm, $subjectOffering);
            [$policy, $scale] = $this->resolveReferences($academicTerm, $subjectOffering, (string) $input['policy_id'], (string) $input['grading_scale_id']);
            $assessmentDate = $this->dateWithinTerm($academicTerm, (string) $input['assessment_date']);
            $duplicate = AcademicAssessmentInstance::query()->where('academic_subject_offering_id', $subjectOffering->getKey())->where('academic_assessment_policy_version_id', $policy->getKey())->where('academic_grading_scale_version_id', $scale->getKey())->whereDate('assessment_date', $assessmentDate->toDateString())->whereIn('status', ['draft', 'open'])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['assessment_date' => ['An active assessment instance already exists for this offering, policy, grading scale and date.']]);
            }
            $instance = AcademicAssessmentInstance::query()->create([
                'academic_session_id' => $academicTerm->academic_session_id,
                'academic_term_id' => $academicTerm->getKey(),
                'academic_subject_offering_id' => $subjectOffering->getKey(),
                'academic_assessment_policy_version_id' => $policy->getKey(),
                'academic_grading_scale_version_id' => $scale->getKey(),
                'title' => trim((string) $input['title']),
                'assessment_date' => $assessmentDate->toDateString(),
                'instructions' => $this->nullableString($input['instructions'] ?? null),
                'status' => 'draft',
                'created_by' => $actor->getKey(),
            ]);
            $this->audit($actor, 'academic.assessment_instance_created', (string) $instance->public_id, ['to' => 'draft']);

            return $this->data($instance->load(['policy', 'gradingScale']));
        });
    }

    /** @param array{title: string, assessment_date: string, instructions?: string|null} $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, array $input): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessments.manage');
        $this->assertParentsOpen($academicTerm, $subjectOffering);
        $record = $this->find($instance, $academicTerm, $subjectOffering, true);
        if ($record->status !== 'draft') {
            throw ValidationException::withMessages(['assessment_instance' => ['Only draft assessment instances can be edited.']]);
        }
        $assessmentDate = $this->dateWithinTerm($academicTerm, (string) $input['assessment_date']);
        $record->update(['title' => trim((string) $input['title']), 'assessment_date' => $assessmentDate->toDateString(), 'instructions' => $this->nullableString($input['instructions'] ?? null)]);
        $this->audit($actor, 'academic.assessment_instance_updated', (string) $record->public_id, ['status' => 'draft']);

        return $this->data($record->fresh(['policy', 'gradingScale']));
    }

    /** @return array<string, mixed> */
    public function transition(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, string $target): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessments.manage');

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $instance, $target, $school): array {
            $record = $this->find($instance, $academicTerm, $subjectOffering, true);
            if ($record->status === $target) {
                return $this->data($record);
            }
            if ($target === 'open') {
                $this->assertParentsOpen($academicTerm, $subjectOffering);
                $readiness = $this->readiness->execute($actor, $school);
                if (! $readiness['ready']) {
                    throw ValidationException::withMessages(['assessment_instance' => ['Academic readiness requirements are not complete.']]);
                }
                if ($record->status !== 'draft') {
                    throw ValidationException::withMessages(['assessment_instance' => ['Only draft assessment instances can be opened.']]);
                }
                $record->update(['status' => 'open', 'opened_by' => $actor->getKey(), 'opened_at' => now()]);
            } elseif ($target === 'closed') {
                if ($record->status !== 'open') {
                    throw ValidationException::withMessages(['assessment_instance' => ['Only open assessment instances can be closed.']]);
                }
                $record->update(['status' => 'closed', 'closed_by' => $actor->getKey(), 'closed_at' => now()]);
            } elseif ($target === 'cancelled') {
                if (! in_array($record->status, ['draft', 'open'], true)) {
                    throw ValidationException::withMessages(['assessment_instance' => ['Only draft or open assessment instances can be cancelled.']]);
                }
                $record->update(['status' => 'cancelled', 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()]);
            } else {
                throw ValidationException::withMessages(['assessment_instance' => ['The requested lifecycle transition is invalid.']]);
            }
            $this->audit($actor, 'academic.assessment_instance_'.$target, (string) $record->public_id, ['to' => $target]);

            return $this->data($record->fresh(['policy', 'gradingScale']));
        });
    }

    /** @return array{0: AcademicTerm, 1: AcademicSubjectOffering} */
    private function authorizeOffering(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        $subjectOffering = AcademicSubjectOffering::query()->where('school_id', $context->schoolId)->where('public_id', $offering)->where('academic_term_id', $academicTerm?->getKey())->first();
        if (! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $subjectOffering];
    }

    private function assertParentsOpen(AcademicTerm $term, AcademicSubjectOffering $offering): void
    {
        $term->loadMissing('session');
        if ($term->status === 'closed' || $term->session?->status !== 'active' || $offering->status !== 'active') {
            throw ValidationException::withMessages(['assessment_instance' => ['The offering and academic term must be active.']]);
        }
    }

    /** @return array{0: AcademicAssessmentPolicyVersion, 1: AcademicGradingScaleVersion} */
    private function resolveReferences(AcademicTerm $term, AcademicSubjectOffering $offering, string $policyId, string $scaleId): array
    {
        $policy = AcademicAssessmentPolicyVersion::query()->where('public_id', $policyId)->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->first();
        $scale = $policy instanceof AcademicAssessmentPolicyVersion ? AcademicGradingScaleVersion::query()->where('public_id', $scaleId)->where('academic_assessment_policy_version_id', $policy->getKey())->first() : null;
        if (! $policy instanceof AcademicAssessmentPolicyVersion || ! $scale instanceof AcademicGradingScaleVersion || $policy->status !== 'active' || $scale->status !== 'active') {
            throw new ModelNotFoundException;
        }

        return [$policy, $scale];
    }

    private function dateWithinTerm(AcademicTerm $term, string $date): CarbonImmutable
    {
        $assessmentDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if (! $assessmentDate || $assessmentDate->lt(CarbonImmutable::parse((string) $term->start_date)) || $assessmentDate->gt(CarbonImmutable::parse((string) $term->end_date))) {
            throw ValidationException::withMessages(['assessment_date' => ['The assessment date must fall within the academic term.']]);
        }

        return $assessmentDate;
    }

    private function find(string $publicId, AcademicTerm $term, AcademicSubjectOffering $offering, bool $lock = false): AcademicAssessmentInstance
    {
        $query = AcademicAssessmentInstance::query()->where('public_id', $publicId)->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->with(['policy', 'gradingScale']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $instance = $query->first();
        if (! $instance instanceof AcademicAssessmentInstance) {
            throw new ModelNotFoundException;
        }

        return $instance;
    }

    /** @param array<string, string> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_assessment_instance', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.assessments.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(AcademicAssessmentInstance $instance): array
    {
        return ['id' => (string) $instance->public_id, 'session_id' => (string) $instance->session?->public_id, 'term_id' => (string) $instance->term?->public_id, 'offering_id' => (string) $instance->offering?->public_id, 'policy_id' => (string) $instance->policy?->public_id, 'grading_scale_id' => (string) $instance->gradingScale?->public_id, 'title' => (string) $instance->title, 'assessment_date' => CarbonImmutable::parse((string) $instance->assessment_date)->toDateString(), 'instructions' => $instance->instructions === null ? null : (string) $instance->instructions, 'status' => (string) $instance->status, 'opened_at' => $this->dateTimeString($instance->opened_at), 'closed_at' => $this->dateTimeString($instance->closed_at), 'cancelled_at' => $this->dateTimeString($instance->cancelled_at)];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function dateTimeString(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
