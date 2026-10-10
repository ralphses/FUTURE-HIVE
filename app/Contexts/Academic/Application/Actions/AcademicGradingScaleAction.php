<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyVersion;
use App\Contexts\Academic\Domain\Models\AcademicGradingBand;
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

final class AcademicGradingScaleAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy): array
    {
        [$policyVersion] = $this->authorizePolicy($actor, $school, $session, $term, $offering, $policy, 'academic.grading-scales.read');

        return ['items' => AcademicGradingScaleVersion::query()->where('academic_assessment_policy_version_id', $policyVersion->getKey())->with(['bands', 'policyVersion'])->orderByDesc('version')->get()->map(fn (AcademicGradingScaleVersion $scale): array => $this->data($scale))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy, string $scale): array
    {
        [$policyVersion] = $this->authorizePolicy($actor, $school, $session, $term, $offering, $policy, 'academic.grading-scales.read');

        return $this->data($this->find($scale, $policyVersion));
    }

    /** @param array{name: string, effective_start: string, effective_end?: string|null, bands: list<array<string, mixed>>} $data
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy, array $data): array
    {
        [$policyVersion, $academicTerm] = $this->authorizePolicy($actor, $school, $session, $term, $offering, $policy, 'academic.grading-scales.manage');

        return DB::transaction(function () use ($actor, $policyVersion, $academicTerm, $data): array {
            $policyVersion = AcademicAssessmentPolicyVersion::query()->whereKey($policyVersion->getKey())->lockForUpdate()->firstOrFail();
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPolicyCanConfigure($policyVersion, $academicTerm, $data['effective_start'], $data['effective_end'] ?? null);
            $this->validateBands($data['bands']);
            $version = ((int) AcademicGradingScaleVersion::query()->where('academic_assessment_policy_version_id', $policyVersion->getKey())->max('version')) + 1;
            $scale = AcademicGradingScaleVersion::query()->create([
                'academic_assessment_policy_version_id' => $policyVersion->getKey(),
                'version' => $version,
                'name' => trim($data['name']),
                'effective_start' => $data['effective_start'],
                'effective_end' => $data['effective_end'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->getKey(),
            ]);
            foreach ($data['bands'] as $band) {
                AcademicGradingBand::query()->create([
                    'academic_grading_scale_version_id' => $scale->getKey(),
                    'grade' => trim((string) $band['grade']),
                    'label' => trim((string) $band['label']),
                    'minimum_percentage' => $band['minimum_percentage'],
                    'maximum_percentage' => $band['maximum_percentage'],
                    'is_passing' => (bool) $band['is_passing'],
                    'remark' => isset($band['remark']) ? trim((string) $band['remark']) : null,
                    'sequence' => (int) $band['sequence'],
                ]);
            }
            $this->audit($actor, 'academic.grading_scale_created', (string) $scale->public_id, ['version' => $version, 'band_count' => count($data['bands'])]);

            return $this->data($scale->load(['bands', 'policyVersion']));
        });
    }

    /** @return array<string, mixed> */
    public function activate(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy, string $scale): array
    {
        [$policyVersion, $academicTerm] = $this->authorizePolicy($actor, $school, $session, $term, $offering, $policy, 'academic.grading-scales.manage');

        return DB::transaction(function () use ($actor, $policyVersion, $academicTerm, $scale): array {
            $policyVersion = AcademicAssessmentPolicyVersion::query()->whereKey($policyVersion->getKey())->lockForUpdate()->firstOrFail();
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPolicyCanActivate($policyVersion, $academicTerm);
            $gradingScale = $this->find($scale, $policyVersion, true);
            if ($gradingScale->status === 'active') {
                return $this->data($gradingScale);
            }
            if ($gradingScale->status === 'retired') {
                throw ValidationException::withMessages(['grading_scale' => ['Retired grading scales cannot be reactivated.']]);
            }
            if (AcademicGradingScaleVersion::query()->where('academic_assessment_policy_version_id', $policyVersion->getKey())->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['grading_scale' => ['Another grading scale is already active for this policy version.']]);
            }
            $gradingScale->update(['status' => 'active', 'activated_by' => $actor->getKey(), 'activated_at' => now()]);
            $this->audit($actor, 'academic.grading_scale_activated', (string) $gradingScale->public_id, ['version' => (int) $gradingScale->version, 'status' => 'active']);

            return $this->data($gradingScale->fresh(['bands', 'policyVersion']));
        });
    }

    /** @return array<string, mixed> */
    public function retire(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy, string $scale): array
    {
        [$policyVersion, $academicTerm] = $this->authorizePolicy($actor, $school, $session, $term, $offering, $policy, 'academic.grading-scales.manage');

        return DB::transaction(function () use ($actor, $policyVersion, $academicTerm, $scale): array {
            $policyVersion = AcademicAssessmentPolicyVersion::query()->whereKey($policyVersion->getKey())->lockForUpdate()->firstOrFail();
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPolicyCanConfigure($policyVersion, $academicTerm, (string) $policyVersion->effective_start, $policyVersion->effective_end?->toDateString());
            $gradingScale = $this->find($scale, $policyVersion, true);
            if ($gradingScale->status === 'retired') {
                return $this->data($gradingScale);
            }
            if ($gradingScale->status !== 'active') {
                throw ValidationException::withMessages(['grading_scale' => ['Only active grading scales can be retired.']]);
            }
            $gradingScale->update(['status' => 'retired', 'retired_by' => $actor->getKey(), 'retired_at' => now()]);
            $this->audit($actor, 'academic.grading_scale_retired', (string) $gradingScale->public_id, ['version' => (int) $gradingScale->version, 'status' => 'retired']);

            return $this->data($gradingScale->fresh(['bands', 'policyVersion']));
        });
    }

    /** @return array{0: AcademicAssessmentPolicyVersion, 1: AcademicTerm} */
    private function authorizePolicy(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $policy, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $policyVersion = AcademicAssessmentPolicyVersion::query()->where('public_id', $policy)->whereHas('components')->first();
        $academicTerm = $policyVersion instanceof AcademicAssessmentPolicyVersion ? AcademicTerm::query()->whereKey($policyVersion->academic_term_id)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('public_id', $session))->first() : null;
        $offeringModel = $academicTerm instanceof AcademicTerm ? AcademicSubjectOffering::query()->whereKey($policyVersion->academic_subject_offering_id)->where('public_id', $offering)->where('academic_term_id', $academicTerm->getKey())->first() : null;
        if (! $policyVersion instanceof AcademicAssessmentPolicyVersion || ! $academicTerm instanceof AcademicTerm || ! $offeringModel instanceof AcademicSubjectOffering) {
            throw new ModelNotFoundException;
        }

        return [$policyVersion, $academicTerm];
    }

    private function assertPolicyCanConfigure(AcademicAssessmentPolicyVersion $policy, AcademicTerm $term, string $start, ?string $end): void
    {
        if ($policy->status === 'retired' || $term->status === 'closed') {
            throw ValidationException::withMessages(['grading_scale' => ['The assessment policy or academic term is no longer configurable.']]);
        }
        $this->assertDates($policy, $term, $start, $end);
    }

    private function assertPolicyCanActivate(AcademicAssessmentPolicyVersion $policy, AcademicTerm $term): void
    {
        if ($policy->status !== 'active' || $term->status === 'closed') {
            throw ValidationException::withMessages(['grading_scale' => ['An active policy and open academic term are required.']]);
        }
    }

    private function assertDates(AcademicAssessmentPolicyVersion $policy, AcademicTerm $term, string $start, ?string $end): void
    {
        $effectiveStart = CarbonImmutable::parse($start);
        $effectiveEnd = $end === null || trim($end) === '' ? null : CarbonImmutable::parse($end);
        $lower = CarbonImmutable::parse((string) $term->start_date)->max(CarbonImmutable::parse((string) $policy->effective_start));
        $upper = CarbonImmutable::parse((string) $term->end_date)->min(CarbonImmutable::parse((string) ($policy->effective_end ?? $term->end_date)));
        if ($effectiveStart->lt($lower) || $effectiveStart->gt($upper) || ($effectiveEnd !== null && ($effectiveEnd->lt($effectiveStart) || $effectiveEnd->gt($upper)))) {
            throw ValidationException::withMessages(['effective_start' => ['Grading scale dates must fall within the policy and academic term.']]);
        }
    }

    /** @param list<array<string, mixed>> $bands */
    private function validateBands(array $bands): void
    {
        $normalized = collect($bands)->map(function (array $band): array {
            return ['minimum' => $this->hundredths((string) $band['minimum_percentage']), 'maximum' => $this->hundredths((string) $band['maximum_percentage']), 'sequence' => (int) $band['sequence'], 'passing' => (bool) $band['is_passing']];
        })->sortBy('minimum')->values()->all();
        $expectedStart = 0;
        $sequences = [];
        $hasPassing = false;
        foreach ($normalized as $band) {
            if ($band['minimum'] > $band['maximum'] || $band['minimum'] !== $expectedStart || in_array($band['sequence'], $sequences, true)) {
                throw ValidationException::withMessages(['bands' => ['Grading bands must be non-overlapping, contiguous and uniquely sequenced.']]);
            }
            $sequences[] = $band['sequence'];
            $expectedStart = $band['maximum'] + 1;
            $hasPassing = $hasPassing || $band['passing'];
        }
        if ($expectedStart !== 10001 || ! $hasPassing) {
            throw ValidationException::withMessages(['bands' => ['Grading bands must cover 0 through 100 and include at least one passing band.']]);
        }
    }

    private function hundredths(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function find(string $publicId, AcademicAssessmentPolicyVersion $policy, bool $lock = false): AcademicGradingScaleVersion
    {
        $query = AcademicGradingScaleVersion::query()->where('public_id', $publicId)->where('academic_assessment_policy_version_id', $policy->getKey())->with(['bands', 'policyVersion']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $scale = $query->first();
        if (! $scale instanceof AcademicGradingScaleVersion) {
            throw new ModelNotFoundException;
        }

        return $scale;
    }

    /** @param array<string, int|string> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_grading_scale_version', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.grading-scales.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(AcademicGradingScaleVersion $scale): array
    {
        return [
            'id' => (string) $scale->public_id,
            'version' => (int) $scale->version,
            'name' => (string) $scale->name,
            'policy_id' => (string) ($scale->policyVersion?->public_id ?? ''),
            'effective_start' => CarbonImmutable::parse((string) $scale->effective_start)->toDateString(),
            'effective_end' => $scale->effective_end === null ? null : CarbonImmutable::parse((string) $scale->effective_end)->toDateString(),
            'status' => (string) $scale->status,
            'bands' => $scale->bands->map(fn (AcademicGradingBand $band): array => [
                'id' => (string) $band->public_id,
                'grade' => (string) $band->grade,
                'label' => (string) $band->label,
                'minimum_percentage' => (string) $band->minimum_percentage,
                'maximum_percentage' => (string) $band->maximum_percentage,
                'is_passing' => (bool) $band->is_passing,
                'remark' => $band->remark === null ? null : (string) $band->remark,
                'sequence' => (int) $band->sequence,
            ])->values()->all(),
        ];
    }
}
