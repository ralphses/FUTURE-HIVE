<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentAccessWindow;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
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

final class AcademicAssessmentAccessWindowAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{id: string|null, assessment_instance_id: string, opens_at: string|null, closes_at: string|null, status: string|null, currently_open: bool, activated_at: string|null, closed_at: string|null} */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance, 'academic.assessment-access.read');
        $window = AcademicAssessmentAccessWindow::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('academic_assessment_instance_id', $assessmentInstance->getKey())
            ->first();

        return $window instanceof AcademicAssessmentAccessWindow ? $this->data($window) : [
            'id' => null,
            'assessment_instance_id' => (string) $assessmentInstance->public_id,
            'opens_at' => null,
            'closes_at' => null,
            'status' => null,
            'currently_open' => false,
            'activated_at' => null,
            'closed_at' => null,
        ];
    }

    /** @param array{opens_at: string, closes_at: string} $input
     * @return array{id: string|null, assessment_instance_id: string, opens_at: string|null, closes_at: string|null, status: string|null, currently_open: bool, activated_at: string|null, closed_at: string|null}
     */
    public function update(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance, array $input): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance, 'academic.assessment-access.manage');
        $this->assertManageableParents($academicTerm, $subjectOffering, $assessmentInstance);
        $opensAt = $this->parseDateTime((string) $input['opens_at']);
        $closesAt = $this->parseDateTime((string) $input['closes_at']);
        $this->assertWindowDates($academicTerm, $opensAt, $closesAt);

        return DB::transaction(function () use ($actor, $assessmentInstance, $academicTerm, $subjectOffering, $opensAt, $closesAt): array {
            $window = AcademicAssessmentAccessWindow::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->lockForUpdate()->first();
            if ($window instanceof AcademicAssessmentAccessWindow && $window->status !== 'draft') {
                throw ValidationException::withMessages(['access' => ['Only a draft access window can be edited.']]);
            }
            if (! $window instanceof AcademicAssessmentAccessWindow) {
                $window = AcademicAssessmentAccessWindow::query()->create([
                    'school_id' => TenantContext::require()->schoolId,
                    'academic_session_id' => $academicTerm->academic_session_id,
                    'academic_term_id' => $academicTerm->getKey(),
                    'academic_subject_offering_id' => $subjectOffering->getKey(),
                    'academic_assessment_instance_id' => $assessmentInstance->getKey(),
                    'opens_at' => $opensAt,
                    'closes_at' => $closesAt,
                    'status' => 'draft',
                    'created_by' => $actor->getKey(),
                    'request_id' => Context::get('request_id'),
                ]);
                $this->audit($actor, 'academic.assessment_access_window_created', (string) $window->public_id, ['to' => 'draft']);
            } else {
                $window->update(['opens_at' => $opensAt, 'closes_at' => $closesAt, 'request_id' => Context::get('request_id')]);
                $this->audit($actor, 'academic.assessment_access_window_updated', (string) $window->public_id, ['status' => 'draft']);
            }

            return $this->data($window->fresh());
        });
    }

    /** @return array{id: string|null, assessment_instance_id: string, opens_at: string|null, closes_at: string|null, status: string|null, currently_open: bool, activated_at: string|null, closed_at: string|null} */
    public function activate(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance, 'academic.assessment-access.manage');
        $this->assertManageableParents($academicTerm, $subjectOffering, $assessmentInstance);

        return DB::transaction(function () use ($actor, $assessmentInstance): array {
            $window = AcademicAssessmentAccessWindow::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->lockForUpdate()->first();
            if (! $window instanceof AcademicAssessmentAccessWindow) {
                throw new ModelNotFoundException;
            }
            if ($window->status === 'active') {
                return $this->data($window);
            }
            if ($window->status !== 'draft' || $assessmentInstance->status !== 'open') {
                throw ValidationException::withMessages(['access' => ['Only a draft window for an open assessment instance can be activated.']]);
            }
            $window->update(['status' => 'active', 'activated_by' => $actor->getKey(), 'activated_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.assessment_access_window_activated', (string) $window->public_id, ['from' => 'draft', 'to' => 'active']);

            return $this->data($window->fresh());
        });
    }

    /** @return array{id: string|null, assessment_instance_id: string, opens_at: string|null, closes_at: string|null, status: string|null, currently_open: bool, activated_at: string|null, closed_at: string|null} */
    public function close(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $instance): array
    {
        [$assessmentInstance, $academicTerm, $subjectOffering] = $this->authorizeInstance($actor, $school, $session, $term, $offering, $instance, 'academic.assessment-access.manage');
        $this->assertManageableParents($academicTerm, $subjectOffering, $assessmentInstance);

        return DB::transaction(function () use ($actor, $assessmentInstance): array {
            $window = AcademicAssessmentAccessWindow::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->lockForUpdate()->first();
            if (! $window instanceof AcademicAssessmentAccessWindow) {
                throw new ModelNotFoundException;
            }
            if ($window->status === 'closed') {
                return $this->data($window);
            }
            if (! in_array($window->status, ['draft', 'active'], true)) {
                throw ValidationException::withMessages(['access' => ['The access window lifecycle transition is invalid.']]);
            }
            $window->update(['status' => 'closed', 'closed_by' => $actor->getKey(), 'closed_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.assessment_access_window_closed', (string) $window->public_id, ['from' => (string) $window->getOriginal('status'), 'to' => 'closed']);

            return $this->data($window->fresh());
        });
    }

    public function assertWritable(AcademicAssessmentInstance $assessmentInstance): void
    {
        $window = AcademicAssessmentAccessWindow::query()->where('academic_assessment_instance_id', $assessmentInstance->getKey())->first();
        $now = CarbonImmutable::now();
        if (! $window instanceof AcademicAssessmentAccessWindow || $window->status !== 'active' || $now->lt(CarbonImmutable::parse((string) $window->opens_at)) || ! $now->lt(CarbonImmutable::parse((string) $window->closes_at))) {
            throw ValidationException::withMessages(['access' => ['The assessment access window is not currently open.']]);
        }
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
        $assessmentInstance = AcademicAssessmentInstance::query()->where('school_id', $context->schoolId)->where('public_id', $instance)->where('academic_term_id', $academicTerm?->getKey())->where('academic_subject_offering_id', $subjectOffering?->getKey())->first();
        if (! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering || ! $assessmentInstance instanceof AcademicAssessmentInstance) {
            throw new ModelNotFoundException;
        }

        return [$assessmentInstance, $academicTerm, $subjectOffering];
    }

    private function assertManageableParents(AcademicTerm $term, AcademicSubjectOffering $offering, AcademicAssessmentInstance $instance): void
    {
        $term->loadMissing('session');
        if ($instance->status === 'closed' || $instance->status === 'cancelled' || $term->status === 'closed' || $term->session?->status !== 'active' || $offering->status !== 'active') {
            throw ValidationException::withMessages(['access' => ['The assessment instance and academic parents must remain active.']]);
        }
    }

    private function parseDateTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }

    private function assertWindowDates(AcademicTerm $term, CarbonImmutable $opensAt, CarbonImmutable $closesAt): void
    {
        $termStart = CarbonImmutable::parse((string) $term->start_date)->startOfDay()->utc();
        $termEnd = CarbonImmutable::parse((string) $term->end_date)->endOfDay()->utc();
        if (! $opensAt->lt($closesAt) || $opensAt->lt($termStart) || $closesAt->gt($termEnd)) {
            throw ValidationException::withMessages(['access' => ['The access window must be ordered and remain within the academic term.']]);
        }
    }

    /** @return array{id: string, assessment_instance_id: string, opens_at: string, closes_at: string, status: string, currently_open: bool, activated_at: string|null, closed_at: string|null} */
    private function data(AcademicAssessmentAccessWindow $window): array
    {
        $now = CarbonImmutable::now();
        $opensAt = CarbonImmutable::parse((string) $window->opens_at);
        $closesAt = CarbonImmutable::parse((string) $window->closes_at);

        $currentlyOpen = (bool) ($window->status === 'active' && ! $now->lt($opensAt) && $now->lt($closesAt));

        return [
            'id' => (string) $window->public_id,
            'assessment_instance_id' => (string) $window->instance?->public_id,
            'opens_at' => $opensAt->toISOString(),
            'closes_at' => $closesAt->toISOString(),
            'status' => (string) $window->status,
            'currently_open' => $currentlyOpen,
            'activated_at' => $window->activated_at === null ? null : CarbonImmutable::parse((string) $window->activated_at)->toISOString(),
            'closed_at' => $window->closed_at === null ? null : CarbonImmutable::parse((string) $window->closed_at)->toISOString(),
        ];
    }

    /** @param array<string, string> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_assessment_access_window', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.assessment-access.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }
}
