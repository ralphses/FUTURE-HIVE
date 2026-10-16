<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\PlatformSchoolEntitlement;
use App\Contexts\Platform\Domain\Models\PlatformSubscription;
use App\Contexts\Platform\Domain\Models\PlatformSubscriptionPlan;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class PlatformSubscriptionLifecycleAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{subscription: array<string, mixed>|null} */
    public function current(Authenticatable $actor, string $school): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.read');
        $subscription = $this->latestSubscription();

        return ['subscription' => $subscription instanceof PlatformSubscription ? $this->subscriptionData($subscription, $context->schoolPublicId) : null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{subscription: array<string, mixed>}
     */
    public function create(Authenticatable $actor, string $school, array $input): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.manage');
        $subscription = DB::transaction(function () use ($actor, $context, $input): PlatformSubscription {
            $this->activePlan();
            $current = $this->latestSubscription(true);
            if ($current instanceof PlatformSubscription && ! in_array($this->effectiveStatus($current), ['expired', 'cancelled'], true)) {
                throw new ModelNotFoundException;
            }

            return $this->storeSubscription($actor, $context->schoolId, $input, 'created');
        });

        return ['subscription' => $this->subscriptionData($subscription, $context->schoolPublicId)];
    }

    /** @return array{subscriptions: list<array<string, mixed>>} */
    public function history(Authenticatable $actor, string $school): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.read');

        return ['subscriptions' => PlatformSubscription::query()
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (PlatformSubscription $subscription): array => $this->subscriptionData($subscription, $context->schoolPublicId))
            ->all()];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{subscription: array<string, mixed>}
     */
    public function renew(Authenticatable $actor, string $school, array $input): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.manage');
        $subscription = DB::transaction(function () use ($actor, $context, $input): PlatformSubscription {
            $this->activePlan();
            $current = $this->latestSubscription(true);
            if (! $current instanceof PlatformSubscription || $this->effectiveStatus($current) === 'cancelled') {
                throw new ModelNotFoundException;
            }

            $from = $this->effectiveStatus($current);
            $current->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getAuthIdentifier(),
                'termination_reason' => 'renewed',
            ]);
            $this->recordAudit($actor, 'platform.subscription.renewed', $current, $context->schoolId, ['from' => $from, 'to' => 'cancelled']);

            return $this->storeSubscription($actor, $context->schoolId, $input, 'renewed');
        });

        return ['subscription' => $this->subscriptionData($subscription, $context->schoolPublicId)];
    }

    /** @return array{subscription: array<string, mixed>} */
    public function cancel(Authenticatable $actor, string $school): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.manage');
        $subscription = DB::transaction(function () use ($actor, $context): PlatformSubscription {
            $subscription = $this->latestSubscription(true);
            if (! $subscription instanceof PlatformSubscription) {
                throw new ModelNotFoundException;
            }
            if ($subscription->status !== 'cancelled') {
                $from = $this->effectiveStatus($subscription);
                $subscription->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->getAuthIdentifier(), 'termination_reason' => 'cancelled']);
                $this->recordAudit($actor, 'platform.subscription.cancelled', $subscription, $context->schoolId, ['from' => $from, 'to' => 'cancelled']);
            }

            return $subscription;
        });

        return ['subscription' => $this->subscriptionData($subscription, $context->schoolPublicId)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{subscription: array<string, mixed>}
     */
    public function reopenGrace(Authenticatable $actor, string $school, array $input): array
    {
        $context = $this->authorize($actor, $school, 'platform.subscription-plans.manage');
        $subscription = DB::transaction(function () use ($actor, $context, $input): PlatformSubscription {
            $subscription = $this->latestSubscription(true);
            if (! $subscription instanceof PlatformSubscription || $subscription->status === 'cancelled' || $this->effectiveStatus($subscription) !== 'expired') {
                throw new ModelNotFoundException;
            }
            $graceEndsAt = CarbonImmutable::now()->addDays((int) $input['grace_days']);
            $subscription->update(['status' => 'grace', 'grace_starts_at' => now(), 'grace_ends_at' => $graceEndsAt, 'termination_reason' => null]);
            $this->recordAudit($actor, 'platform.subscription.grace_reopened', $subscription, $context->schoolId, ['from' => 'expired', 'to' => 'grace']);

            return $subscription;
        });

        return ['subscription' => $this->subscriptionData($subscription, $context->schoolPublicId)];
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): TenantContext
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }

        return $context;
    }

    private function activePlan(): PlatformSubscriptionPlan
    {
        $assignment = PlatformSchoolEntitlement::query()->where('status', 'active')->lockForUpdate()->first();
        if (! $assignment instanceof PlatformSchoolEntitlement) {
            throw new ModelNotFoundException;
        }
        $plan = PlatformSubscriptionPlan::query()->whereKey($assignment->platform_subscription_plan_id)->firstOrFail();
        if ($plan->status !== 'active') {
            throw new ModelNotFoundException;
        }

        return $plan;
    }

    private function latestSubscription(bool $lock = false): ?PlatformSubscription
    {
        $query = PlatformSubscription::query()->orderByDesc('created_at')->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param array<string, mixed> $input */
    private function storeSubscription(Authenticatable $actor, int $schoolId, array $input, string $reason): PlatformSubscription
    {
        $now = CarbonImmutable::now();
        $endsAt = CarbonImmutable::parse((string) $input['ends_at']);
        $trialEndsAt = isset($input['trial_ends_at']) ? CarbonImmutable::parse((string) $input['trial_ends_at']) : null;
        if ($endsAt <= $now || ($trialEndsAt !== null && ($trialEndsAt <= $now || $trialEndsAt >= $endsAt))) {
            throw new ModelNotFoundException;
        }
        $graceDays = (int) ($input['grace_days'] ?? 0);
        $status = $trialEndsAt instanceof CarbonImmutable ? 'trial' : 'active';

        $subscription = PlatformSubscription::query()->create([
            'platform_subscription_plan_id' => $this->activePlan()->getKey(),
            'status' => $status,
            'starts_at' => $now,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
            'grace_starts_at' => $endsAt,
            'grace_ends_at' => $graceDays > 0 ? $endsAt->addDays($graceDays) : null,
            'created_by' => $actor->getAuthIdentifier(),
            'request_id' => Context::get('request_id'),
        ]);
        $this->recordAudit($actor, 'platform.subscription.'.$reason, $subscription, $schoolId, ['to' => $status]);

        return $subscription;
    }

    private function effectiveStatus(PlatformSubscription $subscription): string
    {
        if ($subscription->status === 'cancelled') {
            return 'cancelled';
        }
        if ($subscription->status === 'expired') {
            return 'expired';
        }
        $now = CarbonImmutable::now();
        if ($subscription->trial_ends_at !== null && $now < CarbonImmutable::parse((string) $subscription->trial_ends_at)) {
            return 'trial';
        }
        if ($now < CarbonImmutable::parse((string) $subscription->ends_at)) {
            return 'active';
        }
        if ($subscription->grace_ends_at !== null && $now < CarbonImmutable::parse((string) $subscription->grace_ends_at)) {
            return 'grace';
        }

        return 'expired';
    }

    /** @return array<string, mixed> */
    private function subscriptionData(PlatformSubscription $subscription, string $school): array
    {
        $plan = PlatformSubscriptionPlan::query()->whereKey($subscription->platform_subscription_plan_id)->firstOrFail();
        $status = $this->effectiveStatus($subscription);
        $decodedKeys = json_decode(json_encode($plan->entitlement_keys, JSON_THROW_ON_ERROR), true);
        $keys = is_array($decodedKeys) ? array_values(array_map(static fn (mixed $key): string => (string) $key, $decodedKeys)) : [];

        return [
            'id' => (string) $subscription->public_id,
            'school' => $school,
            'plan' => ['id' => (string) $plan->public_id, 'code' => (string) $plan->code, 'version' => (int) $plan->version],
            'status' => $status,
            'entitlement_status' => in_array($status, ['trial', 'active', 'grace'], true) ? 'available' : 'unavailable',
            'entitlement_keys' => $keys,
            'starts_at' => CarbonImmutable::parse((string) $subscription->starts_at)->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at === null ? null : CarbonImmutable::parse((string) $subscription->trial_ends_at)->toISOString(),
            'ends_at' => CarbonImmutable::parse((string) $subscription->ends_at)->toISOString(),
            'grace_starts_at' => $subscription->grace_starts_at === null ? null : CarbonImmutable::parse((string) $subscription->grace_starts_at)->toISOString(),
            'grace_ends_at' => $subscription->grace_ends_at === null ? null : CarbonImmutable::parse((string) $subscription->grace_ends_at)->toISOString(),
            'cancelled_at' => $subscription->cancelled_at === null ? null : CarbonImmutable::parse((string) $subscription->cancelled_at)->toISOString(),
        ];
    }

    /** @param array<string, mixed> $transition */
    private function recordAudit(Authenticatable $actor, string $action, PlatformSubscription $subscription, int $schoolId, array $transition): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'platform_subscription', subjectPublicId: (string) $subscription->public_id, schoolId: $schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), stateTransition: $transition, metadata: ['status' => $subscription->status], requiresSchoolContext: true));
    }
}
