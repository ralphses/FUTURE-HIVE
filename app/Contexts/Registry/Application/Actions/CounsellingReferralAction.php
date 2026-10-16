<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\CounsellingCase;
use App\Contexts\Registry\Domain\Models\CounsellingReferral;
use App\Contexts\Registry\Domain\Models\CounsellorAssignment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class CounsellingReferralAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items:list<array<string,mixed>>} */
    public function index(UserIdentity $actor, string $school, string $type): array
    {
        $this->authorize($actor, $school, $type, 'read');
        $query = CounsellingReferral::query()->where('school_id', TenantContext::require()->schoolId)->where('type', $type)->with(['student', 'case', 'staffProfile', 'assignment']);
        if (! $this->isLeadership($actor)) {
            $query->whereHas('assignment', fn ($q) => $q->where('status', 'active')->whereHas('membership', fn ($m) => $m->where('user_id', $actor->getKey())));
        }

        return ['items' => $query->orderByDesc('requested_at')->orderByDesc('id')->get()->map(fn (CounsellingReferral $referral): array => $this->data($referral))->all()];
    }

    /** @return array<string,mixed> */
    public function show(UserIdentity $actor, string $school, string $type, string $id): array
    {
        $this->authorize($actor, $school, $type, 'read');

        return $this->data($this->accessible($actor, $type, $id));
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(UserIdentity $actor, string $school, string $type, array $input): array
    {
        $this->authorize($actor, $school, $type, 'manage');
        if (! in_array($type, ['referral', 'escalation'], true)) {
            throw new ModelNotFoundException;
        }

        return DB::transaction(function () use ($actor, $type, $input): array {
            $context = TenantContext::require();
            $case = $this->caseForWrite($actor, (string) $input['case_id']);
            $scope = (string) $input['destination_scope'];
            $allowedScopes = $type === 'escalation' ? ['safeguarding_leadership', 'school_leadership'] : ['counselling_staff', 'school_leadership'];
            if (! in_array($scope, $allowedScopes, true)) {
                throw new ModelNotFoundException;
            }
            $key = implode(':', [$context->schoolId, $case->getKey(), $type]);
            $existing = CounsellingReferral::query()->where('active_referral_key', $key)->with(['student', 'case', 'staffProfile'])->lockForUpdate()->first();
            if ($existing instanceof CounsellingReferral) {
                return $this->data($existing);
            }
            $referral = CounsellingReferral::query()->create([
                'school_id' => $context->schoolId,
                'counselling_case_id' => $case->getKey(),
                'counsellor_assignment_id' => $case->counsellor_assignment_id,
                'staff_profile_id' => $case->staff_profile_id,
                'student_id' => $case->student_id,
                'student_enrollment_id' => $case->student_enrollment_id,
                'type' => $type,
                'status' => 'requested',
                'title' => trim((string) $input['title']),
                'purpose' => trim((string) $input['purpose']),
                'urgency' => $input['urgency'],
                'reason_code' => trim((string) $input['reason_code']),
                'destination_scope' => $scope,
                'requested_by' => (int) $actor->getAuthIdentifier(),
                'requested_at' => now(),
                'active_referral_key' => $key,
                'request_id' => Context::get('request_id'),
            ])->load(['student', 'case', 'staffProfile']);
            $this->audit($actor, $type.'_created', $referral, null, 'requested');

            return $this->data($referral);
        });
    }

    /** @return array<string,mixed> */
    public function transition(UserIdentity $actor, string $school, string $type, string $id, string $target, string $reason): array
    {
        $permissionAction = in_array($target, ['approved', 'rejected'], true) ? 'approve' : 'manage';
        $this->authorize($actor, $school, $type, $permissionAction);

        return DB::transaction(function () use ($actor, $type, $id, $target, $reason): array {
            $referral = $this->accessible($actor, $type, $id, true);
            if ($referral->status === $target) {
                return $this->data($referral);
            }
            if (in_array($target, ['approved', 'rejected'], true)) {
                if ($referral->status !== 'requested' || (int) $referral->requested_by === (int) $actor->getAuthIdentifier() || ! $this->isLeadership($actor)) {
                    throw new ModelNotFoundException;
                }
            } elseif ($target === 'accepted' && $referral->status !== 'approved') {
                throw new ModelNotFoundException;
            } elseif ($target === 'closed' && ! in_array($referral->status, ['approved', 'accepted'], true)) {
                throw new ModelNotFoundException;
            } elseif ($target === 'cancelled' && ! in_array($referral->status, ['requested', 'rejected', 'approved'], true)) {
                throw new ModelNotFoundException;
            } elseif ($target !== 'accepted' && $target !== 'closed' && $target !== 'cancelled') {
                throw new ModelNotFoundException;
            }
            $from = (string) $referral->status;
            $referral->status = $target;
            $referral->transition_reason = trim($reason);
            $referral->request_id = Context::get('request_id');
            if (in_array($target, ['approved', 'rejected'], true)) {
                $referral->reviewed_by = (int) $actor->getAuthIdentifier();
                $referral->setAttribute('reviewed_at', now());
                $referral->setAttribute($target.'_at', now());
            }
            if ($target === 'accepted') {
                $referral->accepted_by = (int) $actor->getAuthIdentifier();
                $referral->setAttribute('accepted_at', now());
            }
            $referral->setAttribute($target.'_at', now());
            if (in_array($target, ['rejected', 'closed', 'cancelled'], true)) {
                $referral->active_referral_key = null;
            }
            $referral->save();
            $this->audit($actor, $type.'_'.$target, $referral, $from, $target);

            return $this->data($referral->load(['student', 'case', 'staffProfile']));
        });
    }

    private function caseForWrite(UserIdentity $actor, string $caseId): CounsellingCase
    {
        $case = CounsellingCase::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', $caseId)->with(['assignment.staffProfile.membership.roleAssignments.role', 'student', 'enrollment'])->first();
        if (! $case instanceof CounsellingCase || ! in_array($case->status, ['open', 'on_hold'], true) || ! $this->activeAssignment($case->assignment) || (! $this->isLeadership($actor) && $case->assignment->membership?->user_id !== $actor->getKey())) {
            throw new ModelNotFoundException;
        }

        return $case;
    }

    private function accessible(UserIdentity $actor, string $type, string $id, bool $lock = false): CounsellingReferral
    {
        $query = CounsellingReferral::query()->where('school_id', TenantContext::require()->schoolId)->where('type', $type)->where('public_id', $id)->with(['student', 'case', 'staffProfile', 'assignment.staffProfile.membership.roleAssignments.role']);
        if (! $this->isLeadership($actor)) {
            $query->whereHas('assignment', fn ($q) => $q->where('status', 'active')->whereHas('membership', fn ($m) => $m->where('user_id', $actor->getKey())));
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $referral = $query->first();
        if (! $referral instanceof CounsellingReferral || ! $this->activeAssignment($referral->assignment)) {
            throw new ModelNotFoundException;
        }

        return $referral;
    }

    private function authorize(UserIdentity $actor, string $school, string $type, string $action): void
    {
        $context = TenantContext::require();
        $permission = 'counselling.'.($type === 'escalation' ? 'escalations' : 'referrals').'.'.$action;
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function isLeadership(UserIdentity $actor): bool
    {
        return SchoolMembership::query()->where('school_id', TenantContext::require()->schoolId)->where('user_id', $actor->getKey())->where('status', 'active')->whereHas('roleAssignments', fn ($q) => $q->whereNull('revoked_at')->whereHas('role', fn ($r) => $r->whereIn('key', ['school_admin', 'principal'])))->exists();
    }

    private function activeAssignment(?CounsellorAssignment $assignment): bool
    {
        if (! $assignment instanceof CounsellorAssignment) {
            return false;
        }
        $assignment->loadMissing(['staffProfile.membership.roleAssignments.role', 'student', 'enrollment']);

        return $assignment->status === 'active' && $assignment->staffProfile?->employment_status === 'active' && $assignment->staffProfile->membership?->status === 'active' && $assignment->staffProfile->membership->roleAssignments->contains(fn ($role): bool => $role->revoked_at === null && $role->role?->key === 'counsellor') && $assignment->student?->status === 'active' && $assignment->enrollment?->status === 'active';
    }

    /** @return array<string,mixed> */
    private function data(CounsellingReferral $referral): array
    {
        return ['id' => (string) $referral->public_id, 'type' => (string) $referral->type, 'case_id' => (string) $referral->case?->public_id, 'staff_id' => (string) $referral->staffProfile?->public_id, 'student_id' => (string) $referral->student?->public_id, 'student_number' => (string) $referral->student?->student_number, 'student_name' => (string) $referral->student?->display_name, 'title' => (string) $referral->title, 'purpose' => (string) $referral->purpose, 'urgency' => (string) $referral->urgency, 'reason_code' => (string) $referral->reason_code, 'destination_scope' => (string) $referral->destination_scope, 'status' => (string) $referral->status, 'requested_at' => $this->timestamp($referral->requested_at), 'reviewed_at' => $this->timestamp($referral->reviewed_at), 'accepted_at' => $this->timestamp($referral->accepted_at), 'closed_at' => $this->timestamp($referral->closed_at), 'cancelled_at' => $this->timestamp($referral->cancelled_at)];
    }

    /** @param array<string, scalar|null> $extra */
    private function audit(UserIdentity $actor, string $action, CounsellingReferral $referral, ?string $from, string $to, array $extra = []): void
    {
        $type = $referral->type === 'escalation' ? 'counselling_escalation' : 'counselling_referral';
        $this->audit->execute(new AuditEventData(action: 'counselling.'.$action, subjectType: $type, subjectPublicId: (string) $referral->public_id, schoolId: TenantContext::require()->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'counselling.'.$referral->type.'s.manage'], stateTransition: ['from' => $from, 'to' => $to] + $extra, requiresSchoolContext: true));
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
