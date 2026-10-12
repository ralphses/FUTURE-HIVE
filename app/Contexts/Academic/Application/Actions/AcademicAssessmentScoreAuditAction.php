<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreAudit;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class AcademicAssessmentScoreAuditAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, ?string $student = null, ?string $component = null): LengthAwarePaginator
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance);
        $this->authorizeScope($actor, $subjectOffering, $assessmentInstance);

        $query = AcademicAssessmentScoreAudit::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('academic_assessment_instance_id', $assessmentInstance->getKey())
            ->with(['entry.component', 'student', 'actor'])
            ->when($student !== null, fn ($builder) => $builder->whereHas('student', fn ($studentQuery) => $studentQuery->where('public_id', $student)))
            ->when($component !== null, fn ($builder) => $builder->whereHas('entry.component', fn ($componentQuery) => $componentQuery->where('public_id', $component)))
            ->orderByDesc('created_at')->orderByDesc('id');

        $paginator = $query->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $paginator->setCollection($paginator->getCollection()->map(fn (AcademicAssessmentScoreAudit $audit): array => $this->data($audit, $assessmentInstance, $academicTerm, $subjectOffering)));

        return $paginator;
    }

    /** @return array{0: AcademicAssessmentInstance, 1: AcademicTerm, 2: AcademicSubjectOffering} */
    private function authorizeInstance(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'academic.scores.audit.read')) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        $subjectOffering = AcademicSubjectOffering::query()->where('school_id', $context->schoolId)->where('public_id', $offering)->where('academic_term_id', $academicTerm?->getKey())->first();
        $assessmentInstance = AcademicAssessmentInstance::query()->where('school_id', $context->schoolId)->where('public_id', $instance)->where('academic_term_id', $academicTerm?->getKey())->where('academic_subject_offering_id', $subjectOffering?->getKey())->first();
        if (! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering || ! $assessmentInstance instanceof AcademicAssessmentInstance) {
            throw new ModelNotFoundException;
        }

        return [$assessmentInstance, $academicTerm, $subjectOffering];
    }

    private function authorizeScope(UserIdentity $actor, AcademicSubjectOffering $offering, AcademicAssessmentInstance $instance): void
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

    /** @return array<string, mixed> */
    private function data(AcademicAssessmentScoreAudit $audit, AcademicAssessmentInstance $instance, AcademicTerm $term, AcademicSubjectOffering $offering): array
    {
        return [
            'id' => (string) $audit->public_id,
            'assessment_instance_id' => (string) $instance->public_id,
            'term_id' => (string) $term->public_id,
            'offering_id' => (string) $offering->public_id,
            'score_entry_id' => (string) $audit->entry?->public_id,
            'student_id' => (string) $audit->student?->public_id,
            'component_id' => (string) $audit->entry?->component?->public_id,
            'previous_status' => $audit->previous_status,
            'previous_score' => $audit->previous_score === null ? null : (float) $audit->previous_score,
            'replacement_status' => (string) $audit->replacement_status,
            'replacement_score' => $audit->replacement_score === null ? null : (float) $audit->replacement_score,
            'revision_before' => (int) $audit->revision_before,
            'revision_after' => (int) $audit->revision_after,
            'actor_id' => (string) $audit->actor?->public_id,
            'mutation_source' => (string) $audit->mutation_source,
            'request_id' => $audit->request_id,
            'created_at' => optional($audit->created_at)->toISOString(),
        ];
    }
}
