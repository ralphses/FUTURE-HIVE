<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\PlatformSchoolEntitlement;
use App\Contexts\Platform\Domain\Models\PlatformSubscriptionPlan;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type PlanData array{id: string, code: string, name: string, description: string|null, version: int, entitlement_keys: list<string>, status: string, activated_at: string|null, retired_at: string|null}
 * @phpstan-type PlanInput array{code?: string, name?: string, description?: string|null, entitlement_keys?: list<string>}
 */
final class PlatformSubscriptionPlanAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return list<PlanData> */
    public function index(Authenticatable $actor, string $school): array
    {
        $this->authorize($actor, $school, 'platform.subscription-plans.read');

        return PlatformSubscriptionPlan::query()
            ->orderBy('code')->orderByDesc('version')
            ->get()->map(fn (PlatformSubscriptionPlan $plan): array => $this->planData($plan))->values()->all();
    }

    /** @return array{plan: PlanData} */
    public function show(Authenticatable $actor, string $school, string $planId): array
    {
        $this->authorize($actor, $school, 'platform.subscription-plans.read');

        return ['plan' => $this->planData($this->plan($planId))];
    }

    /** @param array{code: string, name: string, description?: string|null, entitlement_keys: list<string>} $input
     * @return array{plan: PlanData}
     */
    public function create(Authenticatable $actor, string $school, array $input): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.manage');
        $plan = DB::transaction(function () use ($actor, $context, $input): PlatformSubscriptionPlan {
            $version = ((int) PlatformSubscriptionPlan::query()->where('code', $input['code'])->lockForUpdate()->max('version')) + 1;
            $plan = PlatformSubscriptionPlan::query()->create([
                'code' => $input['code'],
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'version' => $version,
                'entitlement_keys' => array_values(array_unique($input['entitlement_keys'])),
                'status' => 'draft',
                'created_by' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->recordAudit($actor, 'platform.subscription_plan.created', $plan, $context->schoolId);

            return $plan;
        });

        return ['plan' => $this->planData($plan)];
    }

    /** @param PlanInput $input
     * @return array{plan: PlanData}
     */
    public function update(Authenticatable $actor, string $school, string $planId, array $input): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.manage');
        $plan = DB::transaction(function () use ($actor, $context, $planId, $input): PlatformSubscriptionPlan {
            $plan = $this->plan($planId, true);
            if ($plan->status !== 'draft') {
                throw new ModelNotFoundException;
            }
            $plan->update([
                'name' => $input['name'] ?? $plan->name,
                'description' => array_key_exists('description', $input) ? $input['description'] : $plan->description,
                'entitlement_keys' => $input['entitlement_keys'] ?? $plan->entitlement_keys,
            ]);
            $this->recordAudit($actor, 'platform.subscription_plan.updated', $plan, $context->schoolId);

            return $plan;
        });

        return ['plan' => $this->planData($plan)];
    }

    /** @return array{plan: PlanData} */
    public function lifecycle(Authenticatable $actor, string $school, string $planId, string $transition): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.manage');
        $plan = DB::transaction(function () use ($actor, $context, $planId, $transition): PlatformSubscriptionPlan {
            $plan = $this->plan($planId, true);
            $target = match ($transition) {
                'activate' => 'active',
                'retire' => 'retired',
                default => throw new ModelNotFoundException,
            };
            if ($plan->status === $target) {
                return $plan;
            }
            $allowed = ['draft' => ['active'], 'active' => ['retired'], 'retired' => []];
            if (! in_array($target, $allowed[$plan->status] ?? [], true)) {
                throw new ModelNotFoundException;
            }
            if ($target === 'active' && PlatformSubscriptionPlan::query()->where('code', $plan->code)->where('status', 'active')->exists()) {
                throw new ModelNotFoundException;
            }
            $plan->update([
                'status' => $target,
                'activated_by' => $target === 'active' ? $actor->getAuthIdentifier() : $plan->activated_by,
                'activated_at' => $target === 'active' ? now() : $plan->activated_at,
                'retired_by' => $target === 'retired' ? $actor->getAuthIdentifier() : $plan->retired_by,
                'retired_at' => $target === 'retired' ? now() : $plan->retired_at,
            ]);
            $this->recordAudit($actor, 'platform.subscription_plan.'.$target, $plan, $context->schoolId, ['from' => $plan->getOriginal('status'), 'to' => $target]);

            return $plan;
        });

        return ['plan' => $this->planData($plan)];
    }

    /** @return array{entitlement: array{id: string|null, status: string, plan: PlanData|null, school: string|null}} */
    public function entitlement(Authenticatable $actor, string $school): array
    {
        $context = $this->authorize($actor, $school, 'platform.entitlements.read');
        $assignment = PlatformSchoolEntitlement::query()->where('status', 'active')->first();
        if (! $assignment instanceof PlatformSchoolEntitlement) {
            return ['entitlement' => ['id' => null, 'status' => 'unassigned', 'plan' => null, 'school' => null]];
        }
        $plan = $this->planByKey((int) $assignment->platform_subscription_plan_id);

        return ['entitlement' => ['id' => (string) $assignment->public_id, 'status' => (string) $assignment->status, 'plan' => $this->planData($plan), 'school' => $context->schoolPublicId]];
    }

    /** @return array{entitlement: array{id: string, status: string, plan: PlanData, school: string}} */
    public function assign(Authenticatable $actor, string $school, string $planId): array
    {
        $context = $this->authorize($actor, $school, 'platform.entitlements.manage');
        $assignment = DB::transaction(function () use ($actor, $context, $planId): PlatformSchoolEntitlement {
            $plan = $this->plan($planId, true);
            if ($plan->status !== 'active') {
                throw new ModelNotFoundException;
            }
            $existing = PlatformSchoolEntitlement::query()->where('status', 'active')->lockForUpdate()->first();
            if ($existing instanceof PlatformSchoolEntitlement && (int) $existing->platform_subscription_plan_id === (int) $plan->getKey()) {
                return $existing;
            }
            if ($existing instanceof PlatformSchoolEntitlement) {
                $existing->update(['status' => 'revoked', 'revoked_by' => $actor->getAuthIdentifier(), 'revoked_at' => now()]);
            }
            $assignment = PlatformSchoolEntitlement::query()->create([
                'platform_subscription_plan_id' => $plan->getKey(),
                'status' => 'active',
                'created_by' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->recordAudit($actor, 'platform.school_entitlement.assigned', $assignment, $context->schoolId, ['plan' => $plan->public_id], 'platform_school_entitlement');

            return $assignment;
        });
        $plan = $this->planByKey((int) $assignment->platform_subscription_plan_id);

        return ['entitlement' => ['id' => (string) $assignment->public_id, 'status' => (string) $assignment->status, 'plan' => $this->planData($plan), 'school' => $context->schoolPublicId]];
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): TenantContext
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }

        return $context;
    }

    private function plan(string $publicId, bool $lock = false): PlatformSubscriptionPlan
    {
        $query = PlatformSubscriptionPlan::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function planByKey(int $id): PlatformSubscriptionPlan
    {
        return PlatformSubscriptionPlan::query()->whereKey($id)->firstOrFail();
    }

    /**
     * @return array{id: string, code: string, name: string, description: string|null, version: int, entitlement_keys: list<string>, status: string, activated_at: string|null, retired_at: string|null}
     */
    private function planData(PlatformSubscriptionPlan $plan): array
    {
        $decodedEntitlementKeys = json_decode(json_encode($plan->entitlement_keys, JSON_THROW_ON_ERROR), true);
        $entitlementKeys = is_array($decodedEntitlementKeys) ? array_values(array_map(static fn (mixed $key): string => (string) $key, $decodedEntitlementKeys)) : [];

        return ['id' => (string) $plan->public_id, 'code' => (string) $plan->code, 'name' => (string) $plan->name, 'description' => $plan->description, 'version' => (int) $plan->version, 'entitlement_keys' => $entitlementKeys, 'status' => (string) $plan->status, 'activated_at' => $plan->activated_at === null ? null : CarbonImmutable::parse((string) $plan->activated_at)->toISOString(), 'retired_at' => $plan->retired_at === null ? null : CarbonImmutable::parse((string) $plan->retired_at)->toISOString()];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $transition */
    private function recordAudit(Authenticatable $actor, string $action, object $subject, int $auditSchoolId, array $transition = [], string $subjectType = 'platform_subscription_plan'): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: $subjectType, subjectPublicId: (string) $subject->public_id, schoolId: $auditSchoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), stateTransition: $transition, metadata: ['status' => $subject->status ?? null], requiresSchoolContext: true));
    }
}
