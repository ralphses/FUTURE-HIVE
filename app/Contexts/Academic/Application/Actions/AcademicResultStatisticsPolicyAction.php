<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicResultStatisticsPolicy;
use App\Contexts\Academic\Domain\Models\AcademicSession;
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

final class AcademicResultStatisticsPolicyAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{policies: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.statistics.read');
        $policies = AcademicResultStatisticsPolicy::query()->where('school_id', TenantContext::require()->schoolId)->where('academic_session_id', $academicSession->getKey())->where('academic_term_id', $academicTerm->getKey())->with(['session', 'term'])->orderByDesc('version')->orderByDesc('id')->get();

        return ['policies' => $policies->map(fn (AcademicResultStatisticsPolicy $policy): array => $this->data($policy))->all()];
    }

    /** @param array{included_scope: string, passing_result_eligibility: bool, tie_handling: string, minimum_eligible_population: int, privacy_suppression_threshold: int} $data
     * @return array{policy: array<string, mixed>}
     */
    public function create(UserIdentity $actor, string $school, string $session, string $term, array $data): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.statistics.manage');

        return DB::transaction(function () use ($actor, $academicSession, $academicTerm, $data): array {
            $query = AcademicResultStatisticsPolicy::query()->where('school_id', TenantContext::require()->schoolId)->where('academic_session_id', $academicSession->getKey())->where('academic_term_id', $academicTerm->getKey())->lockForUpdate();
            $version = ((int) $query->max('version')) + 1;
            $policy = AcademicResultStatisticsPolicy::query()->create([...$data, 'school_id' => TenantContext::require()->schoolId, 'academic_session_id' => $academicSession->getKey(), 'academic_term_id' => $academicTerm->getKey(), 'version' => $version, 'status' => 'draft', 'created_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_statistics_policy_created', (string) $policy->public_id, ['to' => 'draft', 'version' => $version]);

            return ['policy' => $this->data($policy->load(['session', 'term']))];
        });
    }

    /** @return array{policy: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $policyId): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.statistics.read');

        return ['policy' => $this->data($this->policy($policyId, $academicSession, $academicTerm))];
    }

    /** @return array{policy: array<string, mixed>} */
    public function activate(UserIdentity $actor, string $school, string $session, string $term, string $policyId): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.statistics.manage');

        return DB::transaction(function () use ($actor, $academicSession, $academicTerm, $policyId): array {
            $policy = $this->policy($policyId, $academicSession, $academicTerm, true);
            if ($policy->status === 'active') {
                return ['policy' => $this->data($policy)];
            }
            if ($policy->status !== 'draft') {
                throw ValidationException::withMessages(['policy' => ['Only draft policies can be activated.']]);
            }
            $active = AcademicResultStatisticsPolicy::query()->where('school_id', TenantContext::require()->schoolId)->where('academic_session_id', $academicSession->getKey())->where('academic_term_id', $academicTerm->getKey())->where('status', 'active')->lockForUpdate()->first();
            if ($active instanceof AcademicResultStatisticsPolicy) {
                throw ValidationException::withMessages(['policy' => ['Only one active statistics policy is allowed for the term.']]);
            }
            $policy->update(['status' => 'active', 'activated_by' => $actor->getKey(), 'activated_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_statistics_policy_activated', (string) $policy->public_id, ['from' => 'draft', 'to' => 'active']);

            return ['policy' => $this->data($policy->fresh())];
        });
    }

    /** @return array{policy: array<string, mixed>} */
    public function retire(UserIdentity $actor, string $school, string $session, string $term, string $policyId): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.statistics.manage');

        return DB::transaction(function () use ($actor, $academicSession, $academicTerm, $policyId): array {
            $policy = $this->policy($policyId, $academicSession, $academicTerm, true);
            if ($policy->status === 'retired') {
                return ['policy' => $this->data($policy)];
            }
            if ($policy->status !== 'active') {
                throw ValidationException::withMessages(['policy' => ['Only active policies can be retired.']]);
            }
            $policy->update(['status' => 'retired', 'retired_by' => $actor->getKey(), 'retired_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_statistics_policy_retired', (string) $policy->public_id, ['from' => 'active', 'to' => 'retired']);

            return ['policy' => $this->data($policy->fresh())];
        });
    }

    /** @return array{0: AcademicSession, 1: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicSession = AcademicSession::query()->where('school_id', $context->schoolId)->where('public_id', $session)->first();
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('academic_session_id', $academicSession?->getKey())->where('public_id', $term)->first();
        if (! $academicSession instanceof AcademicSession || ! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return [$academicSession, $academicTerm];
    }

    private function policy(string $id, AcademicSession $session, AcademicTerm $term, bool $lock = false): AcademicResultStatisticsPolicy
    {
        $query = AcademicResultStatisticsPolicy::query()->where('school_id', TenantContext::require()->schoolId)->where('academic_session_id', $session->getKey())->where('academic_term_id', $term->getKey())->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function data(AcademicResultStatisticsPolicy $policy): array
    {
        $policy->loadMissing(['session', 'term']);

        return ['id' => (string) $policy->public_id, 'session_id' => (string) $policy->session?->public_id, 'term_id' => (string) $policy->term?->public_id, 'version' => (int) $policy->version, 'included_scope' => (string) $policy->included_scope, 'passing_result_eligibility' => (bool) $policy->passing_result_eligibility, 'tie_handling' => (string) $policy->tie_handling, 'minimum_eligible_population' => (int) $policy->minimum_eligible_population, 'privacy_suppression_threshold' => (int) $policy->privacy_suppression_threshold, 'status' => (string) $policy->status, 'activated_at' => $this->date($policy->activated_at), 'retired_at' => $this->date($policy->retired_at), 'created_at' => $this->date($policy->created_at)];
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    /** @param array<string, mixed> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'academic_result_statistics_policy', subjectPublicId: $publicId, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
