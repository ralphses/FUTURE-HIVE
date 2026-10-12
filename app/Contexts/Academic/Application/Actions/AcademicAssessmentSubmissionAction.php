<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyComponent;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreEntry;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicAssessmentSubmissionAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly AcademicAssessmentAccessWindowAction $accessWindow,
    ) {}

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorize($actor, $school, $session, $term, $offering, $instance, 'academic.scores.submission.read');
        $this->assertActorScope($actor, $academicTerm, $subjectOffering, $assessmentInstance);
        $submission = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->first();

        return $submission instanceof AcademicAssessmentSubmission ? $this->data($submission, $assessmentInstance) : [
            'assessment_instance_id' => (string) $assessmentInstance->public_id,
            'id' => null,
            'status' => null,
            'entry_count' => 0,
            'submitted_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function submit(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorize($actor, $school, $session, $term, $offering, $instance, 'academic.scores.submit');
        $this->assertActorScope($actor, $academicTerm, $subjectOffering, $assessmentInstance);

        return DB::transaction(function () use ($actor, $assessmentInstance, $academicTerm, $subjectOffering): array {
            $assessmentInstance = AcademicAssessmentInstance::query()->whereKey($assessmentInstance->getKey())->lockForUpdate()->firstOrFail();
            $existing = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->lockForUpdate()->first();
            if ($existing instanceof AcademicAssessmentSubmission) {
                if ($existing->status === 'submitted') {
                    return $this->data($existing, $assessmentInstance);
                }

                throw ValidationException::withMessages(['submission' => ['This assessment submission has already entered the review workflow.']]);
            }
            if ($assessmentInstance->status !== 'open') {
                throw ValidationException::withMessages(['assessment_instance' => ['Only open assessment instances can be submitted.']]);
            }
            $this->accessWindow->assertWritable($assessmentInstance);

            $roster = $this->roster($academicTerm, $subjectOffering, CarbonImmutable::parse((string) $assessmentInstance->assessment_date));
            $components = $assessmentInstance->policy->components()->orderBy('sequence')->orderBy('id')->get();
            if ($roster->isEmpty() || $components->isEmpty()) {
                throw ValidationException::withMessages(['scores' => ['An assessment must have an active roster and policy components before submission.']]);
            }
            $expected = $this->assertComplete($assessmentInstance, $roster, $components);

            $submission = AcademicAssessmentSubmission::query()->create([
                'school_id' => TenantContext::require()->schoolId,
                'academic_assessment_instance_id' => $assessmentInstance->getKey(),
                'academic_term_id' => $academicTerm->getKey(),
                'academic_subject_offering_id' => $subjectOffering->getKey(),
                'submitted_by' => $actor->getKey(),
                'status' => 'submitted',
                'entry_count' => $expected,
                'submitted_at' => now(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, (string) $submission->public_id, (string) $assessmentInstance->public_id, $expected);

            return $this->data($submission, $assessmentInstance);
        });
    }

    /** @return array<string, mixed> */
    public function review(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance] = $this->authorize($actor, $school, $session, $term, $offering, $instance, 'academic.scores.review');
        $this->assertLeadership($actor);
        $submission = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->first();

        return $submission instanceof AcademicAssessmentSubmission ? $this->data($submission, $assessmentInstance) : [
            'assessment_instance_id' => (string) $assessmentInstance->public_id,
            'id' => null,
            'status' => null,
            'entry_count' => 0,
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'return_reason' => null,
            'resubmitted_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function returnSubmission(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, string $reason): array
    {
        [$assessmentInstance] = $this->authorize($actor, $school, $session, $term, $offering, $instance, 'academic.scores.return');
        $this->assertLeadership($actor);

        return DB::transaction(function () use ($actor, $assessmentInstance, $reason): array {
            $submission = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->lockForUpdate()->firstOrFail();
            if ($submission->status === 'returned') {
                return $this->data($submission, $assessmentInstance);
            }
            if (! in_array($submission->status, ['submitted', 'resubmitted'], true)) {
                throw ValidationException::withMessages(['submission' => ['Only submitted scores can be returned or approved.']]);
            }
            $submission->update(['status' => 'returned', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'return_reason' => $reason, 'review_request_id' => Context::get('request_id')]);
            $this->reviewAudit($actor, $submission, 'returned', ['reason_present' => true]);

            return $this->data($submission, $assessmentInstance);
        });
    }

    /** @return array<string, mixed> */
    public function approve(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance] = $this->authorize($actor, $school, $session, $term, $offering, $instance, 'academic.scores.approve');
        $this->assertLeadership($actor);

        return DB::transaction(function () use ($actor, $assessmentInstance): array {
            $submission = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->lockForUpdate()->firstOrFail();
            if ($submission->status === 'approved') {
                return $this->data($submission, $assessmentInstance);
            }
            if (! in_array($submission->status, ['submitted', 'resubmitted'], true)) {
                throw ValidationException::withMessages(['submission' => ['Only submitted scores can be returned or approved.']]);
            }
            $submission->update(['status' => 'approved', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_request_id' => Context::get('request_id')]);
            $this->reviewAudit($actor, $submission, 'approved');

            return $this->data($submission, $assessmentInstance);
        });
    }

    /** @return array<string, mixed> */
    public function resubmit(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorize($actor, $school, $session, $term, $offering, $instance, 'academic.scores.resubmit');
        $this->assertActorScope($actor, $academicTerm, $subjectOffering, $assessmentInstance);

        return DB::transaction(function () use ($actor, $assessmentInstance, $academicTerm, $subjectOffering): array {
            $assessmentInstance = AcademicAssessmentInstance::query()->whereKey($assessmentInstance->getKey())->lockForUpdate()->firstOrFail();
            $submission = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->lockForUpdate()->firstOrFail();
            if ($submission->status === 'resubmitted') {
                return $this->data($submission, $assessmentInstance);
            }
            if ($submission->status !== 'returned') {
                throw ValidationException::withMessages(['submission' => ['Only returned scores can be resubmitted.']]);
            }
            if ($assessmentInstance->status !== 'open') {
                throw ValidationException::withMessages(['assessment_instance' => ['Only open assessment instances can be resubmitted.']]);
            }
            $this->accessWindow->assertWritable($assessmentInstance);
            $roster = $this->roster($academicTerm, $subjectOffering, CarbonImmutable::parse((string) $assessmentInstance->assessment_date));
            $components = $assessmentInstance->policy->components()->orderBy('sequence')->orderBy('id')->get();
            $entryCount = $this->assertComplete($assessmentInstance, $roster, $components);
            $submission->update(['status' => 'resubmitted', 'resubmitted_at' => now(), 'request_id' => Context::get('request_id'), 'entry_count' => $entryCount]);
            $this->reviewAudit($actor, $submission, 'resubmitted', ['entry_count' => $entryCount]);

            return $this->data($submission, $assessmentInstance);
        });
    }

    /** @return array{0: AcademicAssessmentInstance, 1: AcademicTerm, 2: AcademicSubjectOffering} */
    private function authorize(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, string $permission): array
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

    private function assertActorScope(UserIdentity $actor, AcademicTerm $term, AcademicSubjectOffering $offering, AcademicAssessmentInstance $instance): void
    {
        $context = TenantContext::require();
        if ($this->isLeadership($actor)) {
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

    private function assertLeadership(UserIdentity $actor): void
    {
        if (! $this->isLeadership($actor)) {
            throw new ModelNotFoundException;
        }
    }

    private function isLeadership(UserIdentity $actor): bool
    {
        $context = TenantContext::require();

        return SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer'])))->exists();
    }

    /** @param Collection<int, StudentEnrollment> $roster
     * @param  Collection<int, AcademicAssessmentPolicyComponent>  $components
     */
    private function assertComplete(AcademicAssessmentInstance $assessmentInstance, Collection $roster, Collection $components): int
    {
        $entries = AcademicAssessmentScoreEntry::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->get()->keyBy(fn (AcademicAssessmentScoreEntry $entry): string => $entry->student_id.':'.$entry->academic_assessment_policy_component_id);
        $expected = $roster->count() * $components->count();
        foreach ($roster as $enrollment) {
            foreach ($components as $component) {
                $entry = $entries->get($enrollment->student_id.':'.$component->getKey());
                if (! $entry instanceof AcademicAssessmentScoreEntry || $entry->status === 'missing') {
                    throw ValidationException::withMessages(['scores' => ['Every active student and assessment component must have an explicit score state before submission.']]);
                }
            }
        }

        return $expected;
    }

    /** @return Collection<int, StudentEnrollment> */
    private function roster(AcademicTerm $term, AcademicSubjectOffering $offering, CarbonImmutable $date): Collection
    {
        return StudentEnrollment::query()->where('school_id', TenantContext::require()->schoolId)->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $offering->academic_class_arm_id)->where('status', 'active')->whereDate('start_date', '<=', $date->toDateString())->where(function ($query) use ($date): void {
            $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString());
        })->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function data(AcademicAssessmentSubmission $submission, AcademicAssessmentInstance $instance): array
    {
        return ['assessment_instance_id' => (string) $instance->public_id, 'id' => (string) $submission->public_id, 'status' => (string) $submission->status, 'entry_count' => (int) $submission->entry_count, 'submitted_at' => CarbonImmutable::parse((string) $submission->submitted_at)->toISOString(), 'reviewed_by' => $submission->reviewer?->public_id, 'reviewed_at' => $submission->reviewed_at === null ? null : CarbonImmutable::parse((string) $submission->reviewed_at)->toISOString(), 'return_reason' => $submission->status === 'returned' ? $submission->return_reason : null, 'resubmitted_at' => $submission->resubmitted_at === null ? null : CarbonImmutable::parse((string) $submission->resubmitted_at)->toISOString()];
    }

    /** @param array<string, mixed> $transition */
    private function reviewAudit(UserIdentity $actor, AcademicAssessmentSubmission $submission, string $status, array $transition = []): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: 'academic.assessment_scores_reviewed', subjectType: 'academic_assessment_submission', subjectPublicId: (string) $submission->public_id, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.scores.review'], stateTransition: array_merge(['assessment_instance_id' => (string) $submission->instance?->public_id, 'to' => $status], $transition), requiresSchoolContext: true));
    }

    private function audit(UserIdentity $actor, string $submissionId, string $instanceId, int $entryCount): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: 'academic.assessment_scores_submitted', subjectType: 'academic_assessment_submission', subjectPublicId: $submissionId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.scores.submit'], stateTransition: ['assessment_instance_id' => $instanceId, 'to' => 'submitted', 'entry_count' => $entryCount], requiresSchoolContext: true));
    }
}
