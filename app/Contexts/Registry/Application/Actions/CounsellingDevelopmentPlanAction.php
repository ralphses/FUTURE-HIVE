<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\CounsellingCase;
use App\Contexts\Registry\Domain\Models\CounsellingDevelopmentPlan;
use App\Contexts\Registry\Domain\Models\CounsellingDevelopmentPlanRevision;
use App\Contexts\Registry\Domain\Models\CounsellingDevelopmentPlanShare;
use App\Contexts\Registry\Domain\Models\CounsellorAssignment;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class CounsellingDevelopmentPlanAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items:list<array<string,mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'counselling.development-plans.read');
        $query = CounsellingDevelopmentPlan::query()->where('school_id', TenantContext::require()->schoolId)->with(['student', 'case', 'shares']);
        if (! $this->isLeadership($actor)) {
            $query->where(function ($plans) use ($actor): void {
                $plans->whereHas('assignment', fn ($q) => $q->where('status', 'active')->whereHas('membership', fn ($m) => $m->where('user_id', $actor->getKey())))
                    ->orWhereHas('shares', fn ($share) => $share->where('scope', 'counselling_staff')->where('status', 'active'));
            });
        }

        return ['items' => $query->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (CounsellingDevelopmentPlan $plan): array => $this->data($plan))->all()];
    }

    /** @return array<string,mixed> */
    public function show(UserIdentity $actor, string $school, string $planId): array
    {
        $this->authorize($actor, $school, 'counselling.development-plans.read');

        return $this->data($this->accessiblePlan($actor, $planId));
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'counselling.development-plans.manage');

        return DB::transaction(function () use ($actor, $input): array {
            $context = TenantContext::require();
            $case = $this->caseForWrite($actor, (string) $input['case_id']);
            $assignment = $case->assignment;
            $key = $context->schoolId.':'.$case->getKey();
            $existing = CounsellingDevelopmentPlan::query()->where('active_plan_key', $key)->with(['student', 'case', 'shares'])->lockForUpdate()->first();
            if ($existing instanceof CounsellingDevelopmentPlan) {
                return $this->data($existing);
            }
            $plan = CounsellingDevelopmentPlan::query()->create($this->fields($actor, $case, $input) + ['school_id' => $context->schoolId, 'status' => 'draft', 'version' => 1, 'active_plan_key' => $key, 'request_id' => Context::get('request_id')]);
            $this->revision($plan, $actor);
            $this->audit($actor, 'counselling.development_plan_created', $plan, null, 'draft');

            return $this->data($plan->load(['student', 'case', 'shares']));
        });
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(UserIdentity $actor, string $school, string $planId, array $input): array
    {
        $this->authorize($actor, $school, 'counselling.development-plans.manage');

        return DB::transaction(function () use ($actor, $planId, $input): array {
            $plan = $this->accessiblePlan($actor, $planId, true);
            if ($plan->status === 'archived' || $plan->status === 'closed') {
                throw new ModelNotFoundException;
            }
            $plan->fill(array_intersect_key($input, array_flip(['title', 'purpose', 'goals', 'action_items', 'review_date', 'progress_state'])));
            $plan->version = (int) $plan->version + 1;
            $plan->request_id = Context::get('request_id');
            $plan->save();
            $this->revision($plan, $actor);
            $this->audit($actor, 'counselling.development_plan_updated', $plan, null, (string) $plan->status);

            return $this->data($plan->load(['student', 'case', 'shares']));
        });
    }

    /** @return array<string,mixed> */
    public function transition(UserIdentity $actor, string $school, string $planId, string $target, string $reason): array
    {
        $this->authorize($actor, $school, 'counselling.development-plans.manage');

        return DB::transaction(function () use ($actor, $planId, $target, $reason): array {
            $plan = $this->accessiblePlan($actor, $planId, true);
            if ($plan->status === $target) {
                return $this->data($plan);
            }
            $allowed = ['active' => ['draft'], 'closed' => ['active'], 'archived' => ['draft', 'active', 'closed']];
            if (! isset($allowed[$target]) || ! in_array($plan->status, $allowed[$target], true)) {
                throw new ModelNotFoundException;
            }
            $from = (string) $plan->status;
            $plan->status = $target;
            $plan->transition_reason = trim($reason);
            $plan->setAttribute($target === 'active' ? 'activated_at' : $target.'_at', now());
            if ($target === 'archived') {
                $plan->active_plan_key = null;
            }
            $plan->request_id = Context::get('request_id');
            $plan->save();
            $this->audit($actor, 'counselling.development_plan_'.$target, $plan, $from, $target);

            return $this->data($plan->load(['student', 'case', 'shares']));
        });
    }

    /** @return array<string,mixed> */
    public function share(UserIdentity $actor, string $school, string $planId, string $scope): array
    {
        $this->authorize($actor, $school, 'counselling.development-plans.share');

        return DB::transaction(function () use ($actor, $planId, $scope): array {
            $plan = $this->accessiblePlan($actor, $planId, true);
            if ($plan->status === 'archived') {
                throw new ModelNotFoundException;
            }
            $share = CounsellingDevelopmentPlanShare::query()->firstOrCreate(['school_id' => TenantContext::require()->schoolId, 'development_plan_id' => $plan->getKey(), 'scope' => $scope], ['status' => 'active', 'created_by' => (int) $actor->getAuthIdentifier(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'counselling.development_plan_shared', $plan, null, (string) $plan->status, ['scope' => $scope, 'share_id' => (string) $share->public_id]);

            return $this->data($plan->load(['student', 'case', 'shares']));
        });
    }

    private function caseForWrite(UserIdentity $actor, string $caseId): CounsellingCase
    {
        $case = CounsellingCase::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', $caseId)->with(['assignment.staffProfile.membership.roleAssignments.role', 'student', 'enrollment'])->first();
        if (! $case instanceof CounsellingCase || in_array($case->status, ['closed', 'archived'], true) || ! $this->activeAssignment($case->assignment) || (! $this->isLeadership($actor) && $case->assignment->membership?->user_id !== $actor->getKey())) {
            throw new ModelNotFoundException;
        }

        return $case;
    }

    private function accessiblePlan(UserIdentity $actor, string $planId, bool $lock = false): CounsellingDevelopmentPlan
    {
        $query = CounsellingDevelopmentPlan::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', $planId)->with(['student', 'case', 'shares', 'assignment.staffProfile.membership.roleAssignments.role']);
        if (! $this->isLeadership($actor)) {
            $query->where(function ($plans) use ($actor): void {
                $plans->whereHas('assignment', fn ($q) => $q->where('status', 'active')->whereHas('membership', fn ($m) => $m->where('user_id', $actor->getKey())))
                    ->orWhereHas('shares', fn ($share) => $share->where('scope', 'counselling_staff')->where('status', 'active'));
            });
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $plan = $query->first();
        if (! $plan instanceof CounsellingDevelopmentPlan || ! $this->activeAssignment($plan->assignment) || (! $this->isLeadership($actor) && ! $this->activeCounsellor($actor))) {
            throw new ModelNotFoundException;
        }

        return $plan;
    }

    private function revision(CounsellingDevelopmentPlan $plan, UserIdentity $actor): void
    {
        CounsellingDevelopmentPlanRevision::query()->create(['school_id' => $plan->school_id, 'development_plan_id' => $plan->getKey(), 'version' => $plan->version, 'title' => $plan->title, 'purpose' => $plan->purpose, 'goals' => $plan->goals, 'action_items' => $plan->action_items, 'review_date' => $plan->review_date, 'progress_state' => $plan->progress_state, 'created_by' => (int) $actor->getAuthIdentifier(), 'request_id' => Context::get('request_id')]);
    }

    /** @return array<string,mixed> */
    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function fields(UserIdentity $actor, CounsellingCase $case, array $input): array
    {
        return ['counselling_case_id' => $case->getKey(), 'student_id' => $case->student_id, 'student_enrollment_id' => $case->student_enrollment_id, 'owner_assignment_id' => $case->counsellor_assignment_id, 'owner_staff_profile_id' => $case->staff_profile_id, 'title' => trim((string) $input['title']), 'purpose' => trim((string) $input['purpose']), 'goals' => trim((string) $input['goals']), 'action_items' => trim((string) $input['action_items']), 'review_date' => $input['review_date'] ?? null, 'progress_state' => $input['progress_state'], 'created_by' => (int) $actor->getAuthIdentifier()];
    }

    /** @return array<string,mixed> */
    private function data(CounsellingDevelopmentPlan $plan): array
    {
        return ['id' => (string) $plan->public_id, 'case_id' => (string) $plan->case?->public_id, 'student_id' => (string) $plan->student?->public_id, 'student_number' => (string) $plan->student?->student_number, 'student_name' => (string) $plan->student?->display_name, 'title' => (string) $plan->title, 'purpose' => (string) $plan->purpose, 'goals' => (string) $plan->goals, 'action_items' => (string) $plan->action_items, 'review_date' => $plan->review_date === null ? null : CarbonImmutable::parse((string) $plan->review_date)->toDateString(), 'progress_state' => (string) $plan->progress_state, 'status' => (string) $plan->status, 'version' => (int) $plan->version, 'shares' => $plan->shares->map(fn (CounsellingDevelopmentPlanShare $share): array => ['id' => (string) $share->public_id, 'scope' => (string) $share->scope, 'status' => (string) $share->status])->values()->all(), 'created_at' => $this->timestamp($plan->created_at), 'activated_at' => $this->timestamp($plan->activated_at), 'closed_at' => $this->timestamp($plan->closed_at), 'archived_at' => $this->timestamp($plan->archived_at)];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
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

    private function activeCounsellor(UserIdentity $actor): bool
    {
        return StaffProfile::query()->where('employment_status', 'active')->whereHas('membership', fn ($membership) => $membership->where('school_id', TenantContext::require()->schoolId)->where('user_id', $actor->getKey())->where('status', 'active')->whereHas('roleAssignments', fn ($roles) => $roles->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->where('key', 'counsellor'))))->exists();
    }

    /** @param array<string, scalar|null> $extra */
    private function audit(UserIdentity $actor, string $action, CounsellingDevelopmentPlan $plan, ?string $from, string $to, array $extra = []): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'counselling_development_plan', subjectPublicId: (string) $plan->public_id, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'counselling.development-plans.manage'], stateTransition: ['from' => $from, 'to' => $to] + $extra, requiresSchoolContext: true));
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
