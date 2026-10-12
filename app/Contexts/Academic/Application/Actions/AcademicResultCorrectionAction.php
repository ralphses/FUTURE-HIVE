<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicResultCorrection;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicResultCorrectionAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly AcademicResultComputationAction $computation,
        private readonly AcademicResultGateAction $gate,
        private readonly AcademicResultSnapshotAction $snapshots,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function create(UserIdentity $actor, string $school, string $session, string $term, string $snapshotId, string $reason): array
    {
        [$academicTerm, $snapshot] = $this->authorizeSnapshot($actor, $school, $session, $term, $snapshotId, 'academic.results.corrections.manage');

        return DB::transaction(function () use ($actor, $academicTerm, $snapshot, $reason): array {
            $existing = AcademicResultCorrection::query()->where('academic_result_snapshot_id', $snapshot->getKey())->where('source_fingerprint', $snapshot->source_fingerprint)->whereIn('status', ['requested', 'approved', 'applied'])->lockForUpdate()->first();
            if ($existing instanceof AcademicResultCorrection) {
                return ['correction' => $this->data($existing)];
            }
            $correction = AcademicResultCorrection::query()->create(['school_id' => TenantContext::require()->schoolId, 'academic_session_id' => $academicTerm->academic_session_id, 'academic_term_id' => $academicTerm->getKey(), 'academic_result_snapshot_id' => $snapshot->getKey(), 'source_result_run_id' => $snapshot->academic_result_run_id, 'source_fingerprint' => $snapshot->source_fingerprint, 'status' => 'requested', 'reason' => trim($reason), 'requested_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_correction_requested', $correction, null, 'requested');

            return ['correction' => $this->data($correction)];
        });
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $snapshotId): LengthAwarePaginator
    {
        [$academicTerm, $snapshot] = $this->authorizeSnapshot($actor, $school, $session, $term, $snapshotId, 'academic.results.corrections.read');

        return AcademicResultCorrection::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_result_snapshot_id', $snapshot->getKey())->latest('id')->paginate(ApiResponse::perPage(request()->integer('per_page')))->through(fn (AcademicResultCorrection $correction): array => $this->data($correction));
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $correctionId): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.corrections.read');
        $correction = AcademicResultCorrection::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $correctionId)->firstOrFail();

        return ['correction' => $this->data($correction)];
    }

    /** @return array<string, mixed> */
    public function approve(UserIdentity $actor, string $school, string $session, string $term, string $correctionId): array
    {
        $this->assertRole($actor, ['school_admin', 'proprietor', 'principal']);

        return DB::transaction(function () use ($actor, $school, $session, $term, $correctionId): array {
            [$academicTerm, $correction] = $this->authorizeCorrection($actor, $school, $session, $term, $correctionId, 'academic.results.corrections.approve', true);
            if ($correction->status === 'approved' || $correction->status === 'applied') {
                return ['correction' => $this->data($correction)];
            }
            if ($correction->status !== 'requested') {
                throw ValidationException::withMessages(['correction' => ['Only requested corrections can be approved.']]);
            }
            $correction->update(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_correction_approved', $correction, 'requested', 'approved');

            return ['correction' => $this->data($correction->fresh())];
        });
    }

    /** @return array<string, mixed> */
    public function reject(UserIdentity $actor, string $school, string $session, string $term, string $correctionId): array
    {
        $this->assertRole($actor, ['school_admin', 'proprietor', 'principal']);

        return DB::transaction(function () use ($actor, $school, $session, $term, $correctionId): array {
            [$academicTerm, $correction] = $this->authorizeCorrection($actor, $school, $session, $term, $correctionId, 'academic.results.corrections.approve', true);
            if ($correction->status === 'rejected') {
                return ['correction' => $this->data($correction)];
            }
            if ($correction->status !== 'requested') {
                throw ValidationException::withMessages(['correction' => ['This correction cannot be rejected.']]);
            }
            $correction->update(['status' => 'rejected', 'rejected_by' => $actor->getKey(), 'rejected_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_correction_rejected', $correction, 'requested', 'rejected');

            return ['correction' => $this->data($correction->fresh())];
        });
    }

    /** @return array<string, mixed> */
    public function republish(UserIdentity $actor, string $school, string $session, string $term, string $correctionId): array
    {
        $this->assertRole($actor, ['proprietor', 'principal']);

        return DB::transaction(function () use ($actor, $school, $session, $term, $correctionId): array {
            [$academicTerm, $correction] = $this->authorizeCorrection($actor, $school, $session, $term, $correctionId, 'academic.results.corrections.republish', true);
            if ($correction->status === 'applied') {
                return $this->dataWithSnapshot($correction);
            }
            if ($correction->status !== 'approved') {
                throw ValidationException::withMessages(['correction' => ['Only approved corrections can be republished.']]);
            }
            $sourceRun = AcademicResultRun::query()->findOrFail($correction->source_result_run_id);
            $result = $this->computation->compute($actor, $school, $session, $term);
            $newRunId = (string) ($result['run']['id'] ?? '');
            if ($newRunId === '' || $newRunId === (string) $sourceRun->public_id || ($result['run']['status'] ?? null) !== 'completed' || (int) ($result['run']['not_computable_count'] ?? 1) !== 0) {
                throw ValidationException::withMessages(['correction' => ['Corrected source data must produce a new complete result run.']]);
            }
            $gate = $this->gate->approve($actor, $school, $session, $term, $newRunId);
            if (($gate['gate']['status'] ?? null) !== 'approved') {
                throw ValidationException::withMessages(['correction' => ['The corrected result run could not be approved.']]);
            }
            $published = $this->gate->publish($actor, $school, $session, $term, $newRunId);
            if (($published['gate']['status'] ?? null) !== 'published') {
                throw ValidationException::withMessages(['correction' => ['The corrected result run could not be published.']]);
            }
            $snapshot = $this->snapshots->create($actor, $school, $session, $term, $newRunId, (string) $correction->snapshot?->public_id);
            $newSnapshotId = (string) ($snapshot['snapshot']['id'] ?? '');
            $newRun = AcademicResultRun::query()->where('public_id', $newRunId)->firstOrFail();
            $newSnapshot = AcademicResultSnapshot::query()->where('public_id', $newSnapshotId)->firstOrFail();
            $correction->update(['status' => 'applied', 'applied_by' => $actor->getKey(), 'applied_at' => now(), 'superseding_result_run_id' => $newRun->getKey(), 'superseding_snapshot_id' => $newSnapshot->getKey(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.result_correction_applied', $correction, 'approved', 'applied');

            return $this->dataWithSnapshot($correction->fresh());
        });
    }

    /** @return array{0: AcademicTerm, 1: AcademicResultSnapshot} */
    private function authorizeSnapshot(UserIdentity $actor, string $school, string $session, string $term, string $snapshotId, string $permission): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, $permission);
        $snapshot = AcademicResultSnapshot::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $snapshotId)->where('status', 'published')->whereHas('gate', fn ($query) => $query->where('status', 'published'))->first();
        if (! $snapshot instanceof AcademicResultSnapshot) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $snapshot];
    }

    /** @return array{0: AcademicTerm, 1: AcademicResultCorrection} */
    private function authorizeCorrection(UserIdentity $actor, string $school, string $session, string $term, string $correctionId, string $permission, bool $lock = false): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, $permission);
        $query = AcademicResultCorrection::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $correctionId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $correction = $query->first();
        if (! $correction instanceof AcademicResultCorrection) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $correction];
    }

    /** @return array{0: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        if (! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm];
    }

    /** @param list<string> $roles */
    private function assertRole(UserIdentity $actor, array $roles): void
    {
        $context = TenantContext::require();
        if (! SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', $roles)))->exists()) {
            throw new ModelNotFoundException;
        }
    }

    /** @return array<string, mixed> */
    private function data(AcademicResultCorrection $correction): array
    {
        return ['id' => (string) $correction->public_id, 'snapshot_id' => (string) $correction->snapshot?->public_id, 'source_result_run_id' => (string) $correction->sourceRun?->public_id, 'status' => (string) $correction->status, 'reason' => (string) $correction->reason, 'superseding_result_run_id' => (string) $correction->supersedingRun?->public_id ?: null, 'superseding_snapshot_id' => (string) $correction->supersedingSnapshot?->public_id ?: null, 'requested_at' => $correction->created_at?->toISOString(), 'approved_at' => $this->date($correction->approved_at), 'rejected_at' => $this->date($correction->rejected_at), 'applied_at' => $this->date($correction->applied_at)];
    }

    /** @return array<string, mixed> */
    private function dataWithSnapshot(AcademicResultCorrection $correction): array
    {
        return ['correction' => $this->data($correction->load(['snapshot', 'sourceRun', 'supersedingRun', 'supersedingSnapshot']))];
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    private function audit(UserIdentity $actor, string $action, AcademicResultCorrection $correction, ?string $from, string $to): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'academic_result_correction', subjectPublicId: (string) $correction->public_id, reason: $correction->reason, stateTransition: ['from' => $from, 'to' => $to], metadata: ['snapshot_id' => (string) $correction->snapshot?->public_id, 'source_result_run_id' => (string) $correction->sourceRun?->public_id, 'request_id' => Context::get('request_id')]));
    }
}
