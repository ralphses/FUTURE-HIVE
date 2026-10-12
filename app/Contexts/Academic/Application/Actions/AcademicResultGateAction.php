<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AcademicResultGateAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $runId): array
    {
        [$academicTerm, $run] = $this->authorizeRun($actor, $school, $session, $term, $runId, 'academic.results.review.read');

        return $this->data($this->gate($run), $academicTerm, $run);
    }

    /** @return array<string, mixed> */
    public function approve(UserIdentity $actor, string $school, string $session, string $term, string $runId): array
    {
        $this->assertRole($actor, $school, ['school_admin', 'proprietor', 'principal']);

        return DB::transaction(function () use ($actor, $school, $session, $term, $runId): array {
            [$academicTerm, $run] = $this->authorizeRun($actor, $school, $session, $term, $runId, 'academic.results.approve');
            $gate = AcademicResultGate::query()->where('academic_result_run_id', $run->getKey())->lockForUpdate()->first();
            if ($gate?->status === 'published' || $gate?->status === 'approved') {
                return $this->data($gate, $academicTerm, $run);
            }
            if ($gate?->status === 'rejected' || $run->status !== 'completed' || (int) $run->not_computable_count !== 0) {
                throw ValidationException::withMessages(['result_run' => ['Only complete result runs can be approved.']]);
            }

            $gate ??= AcademicResultGate::query()->create($this->baseGateData($run));
            $gate->update(['status' => 'approved', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_approved', (string) $run->public_id, 'pending', 'approved', $run);

            return $this->data($gate->fresh(), $academicTerm, $run);
        });
    }

    /** @return array<string, mixed> */
    public function reject(UserIdentity $actor, string $school, string $session, string $term, string $runId, string $reason): array
    {
        $this->assertRole($actor, $school, ['school_admin', 'proprietor', 'principal']);

        return DB::transaction(function () use ($actor, $school, $session, $term, $runId, $reason): array {
            [$academicTerm, $run] = $this->authorizeRun($actor, $school, $session, $term, $runId, 'academic.results.approve');
            $gate = AcademicResultGate::query()->where('academic_result_run_id', $run->getKey())->lockForUpdate()->first();
            if ($gate?->status === 'rejected') {
                return $this->data($gate, $academicTerm, $run);
            }
            if ($gate?->status !== null && $gate->status !== 'pending') {
                throw ValidationException::withMessages(['result_run' => ['This result run cannot be rejected in its current state.']]);
            }

            $gate ??= AcademicResultGate::query()->create($this->baseGateData($run));
            $gate->update(['status' => 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'rejection_reason' => trim($reason), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_rejected', (string) $run->public_id, 'pending', 'rejected', $run, trim($reason));

            return $this->data($gate->fresh(), $academicTerm, $run);
        });
    }

    /** @return array<string, mixed> */
    public function publish(UserIdentity $actor, string $school, string $session, string $term, string $runId): array
    {
        $this->assertRole($actor, $school, ['proprietor', 'principal']);

        return DB::transaction(function () use ($actor, $school, $session, $term, $runId): array {
            [$academicTerm, $run] = $this->authorizeRun($actor, $school, $session, $term, $runId, 'academic.results.publish');
            $gate = AcademicResultGate::query()->where('academic_result_run_id', $run->getKey())->lockForUpdate()->first();
            if ($gate?->status === 'published') {
                return $this->data($gate, $academicTerm, $run);
            }
            if ($gate?->status !== 'approved') {
                throw ValidationException::withMessages(['result_run' => ['Only approved result runs can be published.']]);
            }

            $gate->update(['status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_published', (string) $run->public_id, 'approved', 'published', $run);

            return $this->data($gate->fresh(), $academicTerm, $run);
        });
    }

    /** @return array{0: AcademicTerm, 1: AcademicResultRun} */
    private function authorizeRun(UserIdentity $actor, string $school, string $session, string $term, string $runId, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        $run = AcademicResultRun::query()->where('school_id', $context->schoolId)->where('academic_term_id', $academicTerm?->getKey())->where('public_id', $runId)->first();
        if (! $academicTerm instanceof AcademicTerm || ! $run instanceof AcademicResultRun) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $run];
    }

    /** @param array<int, string> $roles */
    private function assertRole(UserIdentity $actor, string $school, array $roles): void
    {
        $context = TenantContext::require();
        if (! SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', $roles)))->exists()) {
            throw new ModelNotFoundException;
        }
    }

    private function gate(AcademicResultRun $run): AcademicResultGate
    {
        return AcademicResultGate::query()->firstOrCreate(['academic_result_run_id' => $run->getKey()], $this->baseGateData($run));
    }

    /** @return array<string, mixed> */
    private function baseGateData(AcademicResultRun $run): array
    {
        return ['public_id' => (string) Str::uuid7(), 'school_id' => $run->school_id, 'academic_session_id' => $run->academic_session_id, 'academic_term_id' => $run->academic_term_id, 'academic_result_run_id' => $run->getKey(), 'status' => 'pending'];
    }

    /** @return array<string, mixed> */
    private function data(AcademicResultGate $gate, AcademicTerm $term, AcademicResultRun $run): array
    {
        return ['gate' => ['id' => (string) $gate->public_id, 'result_run_id' => (string) $run->public_id, 'session_id' => (string) $term->session?->public_id, 'term_id' => (string) $term->public_id, 'status' => (string) $gate->status, 'computed_count' => (int) $run->computed_count, 'not_computable_count' => (int) $run->not_computable_count, 'rejection_reason' => $gate->rejection_reason, 'reviewed_at' => $gate->reviewed_at === null ? null : CarbonImmutable::parse((string) $gate->reviewed_at)->toISOString(), 'published_at' => $gate->published_at === null ? null : CarbonImmutable::parse((string) $gate->published_at)->toISOString()]];
    }

    private function audit(UserIdentity $actor, string $action, string $subjectId, string $from, string $to, AcademicResultRun $run, ?string $reason = null): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'academic_result_run', subjectPublicId: $subjectId, reason: $reason, stateTransition: ['from' => $from, 'to' => $to], metadata: ['result_run_id' => $subjectId, 'computed_count' => (int) $run->computed_count, 'not_computable_count' => (int) $run->not_computable_count]));
    }
}
