<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyComponent;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyVersion;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScheme;
use App\Contexts\Academic\Domain\Models\AcademicSession;
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

final class AcademicAssessmentPolicyVersionAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessment-policies.read');
        $items = AcademicAssessmentPolicyVersion::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('academic_subject_offering_id', $subjectOffering->getKey())
            ->with('components')
            ->orderByDesc('version')
            ->get()
            ->map(fn (AcademicAssessmentPolicyVersion $version): array => $this->data($version))
            ->all();

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessment-policies.read');

        return $this->data($this->find($policy, $academicTerm, $subjectOffering));
    }

    /** @param array{name?: string|null, effective_start: string, effective_end?: string|null} $data
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $session, string $term, string $offering, array $data): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessment-policies.manage');

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $data): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $subjectOffering = AcademicSubjectOffering::query()->whereKey($subjectOffering->getKey())->lockForUpdate()->firstOrFail();
            $academicSession = AcademicSession::query()->whereKey($academicTerm->academic_session_id)->lockForUpdate()->firstOrFail();
            $this->assertOpenOffering($academicSession, $academicTerm, $subjectOffering);
            $scheme = AcademicAssessmentScheme::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_subject_offering_id', $subjectOffering->getKey())->with('components')->first();
            if (! $scheme instanceof AcademicAssessmentScheme || $scheme->components->isEmpty()) {
                throw ValidationException::withMessages(['assessment_policy' => ['A complete assessment scheme is required before creating a policy version.']]);
            }
            [$start, $end] = $this->validatedDates($academicTerm, (string) $data['effective_start'], $data['effective_end'] ?? null);
            $this->assertNoOverlap($academicTerm, $subjectOffering, $start, $end);
            $versionNumber = ((int) AcademicAssessmentPolicyVersion::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_subject_offering_id', $subjectOffering->getKey())->max('version')) + 1;
            $version = AcademicAssessmentPolicyVersion::query()->create([
                'academic_session_id' => $academicSession->getKey(),
                'academic_term_id' => $academicTerm->getKey(),
                'academic_subject_offering_id' => $subjectOffering->getKey(),
                'academic_assessment_scheme_id' => $scheme->getKey(),
                'version' => $versionNumber,
                'name' => trim((string) ($data['name'] ?? $scheme->name)),
                'total_marks' => $scheme->total_marks,
                'effective_start' => $start->toDateString(),
                'effective_end' => $end?->toDateString(),
                'status' => 'draft',
                'created_by' => $actor->getKey(),
            ]);
            foreach ($scheme->components->sortBy('sequence') as $component) {
                AcademicAssessmentPolicyComponent::query()->create([
                    'academic_assessment_policy_version_id' => $version->getKey(),
                    'name' => $component->name,
                    'category' => $component->category,
                    'max_marks' => $component->max_marks,
                    'sequence' => $component->sequence,
                ]);
            }
            $this->audit($actor, 'academic.assessment_policy_created', (string) $version->public_id, ['version' => $versionNumber, 'component_count' => $scheme->components->count()]);

            return $this->data($version->load('components'));
        });
    }

    /** @return array<string, mixed> */
    public function activate(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessment-policies.manage');

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $policy): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $subjectOffering = AcademicSubjectOffering::query()->whereKey($subjectOffering->getKey())->lockForUpdate()->firstOrFail();
            $academicSession = AcademicSession::query()->whereKey($academicTerm->academic_session_id)->lockForUpdate()->firstOrFail();
            $this->assertOpenOffering($academicSession, $academicTerm, $subjectOffering);
            $version = $this->find($policy, $academicTerm, $subjectOffering, true);
            if ($version->status === 'active') {
                return $this->data($version);
            }
            if ($version->status === 'retired') {
                throw ValidationException::withMessages(['assessment_policy' => ['Retired policy versions cannot be reactivated.']]);
            }
            if (AcademicAssessmentPolicyVersion::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_subject_offering_id', $subjectOffering->getKey())->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['assessment_policy' => ['Another policy version is already active for this offering and term.']]);
            }
            $version->update(['status' => 'active', 'activated_by' => $actor->getKey(), 'activated_at' => now()]);
            $this->audit($actor, 'academic.assessment_policy_activated', (string) $version->public_id, ['version' => (int) $version->version, 'status' => 'active']);

            return $this->data($version->fresh('components'));
        });
    }

    /** @return array<string, mixed> */
    public function retire(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessment-policies.manage');

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $policy): array {
            $version = $this->find($policy, $academicTerm, $subjectOffering, true);
            if ($version->status === 'retired') {
                return $this->data($version);
            }
            if ($version->status !== 'active') {
                throw ValidationException::withMessages(['assessment_policy' => ['Only active policy versions can be retired.']]);
            }
            $version->update(['status' => 'retired', 'retired_by' => $actor->getKey(), 'retired_at' => now()]);
            $this->audit($actor, 'academic.assessment_policy_retired', (string) $version->public_id, ['version' => (int) $version->version, 'status' => 'retired']);

            return $this->data($version->fresh('components'));
        });
    }

    /** @return array{0: AcademicTerm, 1: AcademicSubjectOffering} */
    private function authorizeOffering(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('public_id', $session))->first();
        $subjectOffering = AcademicSubjectOffering::query()->where('public_id', $offering)->where('academic_term_id', $academicTerm?->getKey())->first();
        if (! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $subjectOffering];
    }

    private function assertOpenOffering(AcademicSession $session, AcademicTerm $term, AcademicSubjectOffering $offering): void
    {
        if ($session->status === 'closed' || $term->status === 'closed' || $offering->status !== 'active') {
            throw ValidationException::withMessages(['assessment_policy' => ['Policy versions require an active offering and open term.']]);
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable|null} */
    private function validatedDates(AcademicTerm $term, string $start, ?string $end): array
    {
        $effectiveStart = CarbonImmutable::parse($start);
        $effectiveEnd = $end === null || trim($end) === '' ? null : CarbonImmutable::parse($end);
        $termStart = CarbonImmutable::parse((string) $term->start_date);
        $termEnd = CarbonImmutable::parse((string) $term->end_date);
        if ($effectiveStart->lt($termStart) || $effectiveStart->gt($termEnd) || ($effectiveEnd !== null && ($effectiveEnd->lt($effectiveStart) || $effectiveEnd->gt($termEnd)))) {
            throw ValidationException::withMessages(['effective_start' => ['Policy dates must fall within the academic term.']]);
        }

        return [$effectiveStart, $effectiveEnd];
    }

    private function assertNoOverlap(AcademicTerm $term, AcademicSubjectOffering $offering, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        $endDate = $end?->toDateString() ?? CarbonImmutable::parse((string) $term->end_date)->toDateString();
        $exists = AcademicAssessmentPolicyVersion::query()->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->whereIn('status', ['draft', 'active'])->where('effective_start', '<=', $endDate)->where(function ($query) use ($start): void {
            $query->whereNull('effective_end')->orWhere('effective_end', '>=', $start->toDateString());
        })->exists();
        if ($exists) {
            throw ValidationException::withMessages(['effective_start' => ['Policy dates overlap an existing non-retired version.']]);
        }
    }

    private function find(string $publicId, AcademicTerm $term, AcademicSubjectOffering $offering, bool $lock = false): AcademicAssessmentPolicyVersion
    {
        $query = AcademicAssessmentPolicyVersion::query()->where('public_id', $publicId)->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->with('components');
        if ($lock) {
            $query->lockForUpdate();
        }
        $version = $query->first();
        if (! $version instanceof AcademicAssessmentPolicyVersion) {
            throw new ModelNotFoundException;
        }

        return $version;
    }

    /** @param array<string, int|string> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_assessment_policy_version', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.assessment-policies.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(AcademicAssessmentPolicyVersion $version): array
    {
        return [
            'id' => (string) $version->public_id,
            'version' => (int) $version->version,
            'name' => (string) $version->name,
            'total_marks' => (int) $version->total_marks,
            'effective_start' => CarbonImmutable::parse((string) $version->effective_start)->toDateString(),
            'effective_end' => $version->effective_end === null ? null : CarbonImmutable::parse((string) $version->effective_end)->toDateString(),
            'status' => (string) $version->status,
            'components' => $version->components->sortBy('sequence')->values()->map(fn (AcademicAssessmentPolicyComponent $component): array => [
                'id' => (string) $component->public_id,
                'name' => (string) $component->name,
                'category' => (string) $component->category,
                'max_marks' => (int) $component->max_marks,
                'sequence' => (int) $component->sequence,
            ])->all(),
        ];
    }
}
