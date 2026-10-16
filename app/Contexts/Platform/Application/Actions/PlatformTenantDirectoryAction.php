<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Domain\Models\PlatformSchoolEntitlement;
use App\Contexts\Platform\Domain\Models\PlatformSubscription;
use App\Contexts\Platform\Domain\Models\PlatformSubscriptionPlan;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PlatformTenantDirectoryAction
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Authenticatable $actor, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor);

        $items = DB::table('schools')
            ->select(['id', 'public_id', 'name', 'status', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (object $school): array => $this->projection($school))
            ->filter(fn (array $school): bool => $this->matches($school, $filters))
            ->values();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = ApiResponse::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school): array
    {
        $this->authorize($actor);
        $record = DB::table('schools')
            ->select(['id', 'public_id', 'name', 'status', 'created_at', 'updated_at'])
            ->where('public_id', $school)
            ->first();

        if ($record === null) {
            throw new ModelNotFoundException;
        }

        return ['school' => $this->projection($record)];
    }

    /** @return array<string, mixed> */
    public function entitlements(Authenticatable $actor, string $school): array
    {
        $this->authorize($actor);
        $record = DB::table('schools')->select(['id', 'public_id'])->where('public_id', $school)->first();

        if ($record === null) {
            throw new ModelNotFoundException;
        }

        $projection = TenantContext::runInternal(
            TenantContext::forSchool((int) $record->id, (string) $record->public_id),
            'platform tenant directory entitlement read',
            fn (): array => $this->entitlementProjection((string) $record->public_id),
        );

        return ['school' => (string) $record->public_id, 'entitlement' => $projection];
    }

    /** @return array<string, mixed> */
    private function projection(object $school): array
    {
        $schoolId = (int) $school->id;
        $schoolPublicId = (string) $school->public_id;

        $derived = TenantContext::runInternal(
            TenantContext::forSchool($schoolId, $schoolPublicId),
            'platform tenant directory school projection read',
            fn (): array => [
                'academic_context' => $this->academicProjection($schoolId),
                'setup' => $this->setupProjection($schoolId),
                'subscription' => $this->subscriptionProjection($schoolPublicId),
                'entitlement' => $this->entitlementProjection($schoolPublicId),
            ],
        );

        return [
            'id' => $schoolPublicId,
            'name' => (string) $school->name,
            'status' => (string) $school->status,
            'academic_context' => $derived['academic_context'],
            'setup' => $derived['setup'],
            'subscription' => $derived['subscription'],
            'entitlement' => $derived['entitlement'],
            'created_at' => $this->timestamp($school->created_at),
            'updated_at' => $this->timestamp($school->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function academicProjection(int $schoolId): array
    {
        $activeSessions = DB::table('academic_sessions')->where('school_id', $schoolId)->where('status', 'active')->count();
        $activeTerms = DB::table('academic_terms')->where('school_id', $schoolId)->where('status', 'active')->count();

        return [
            'status' => $activeSessions > 0 && $activeTerms > 0 ? 'available' : 'unavailable',
            'active_session_count' => $activeSessions,
            'active_term_count' => $activeTerms,
        ];
    }

    /** @return array<string, mixed> */
    private function setupProjection(int $schoolId): array
    {
        $total = DB::table('school_setup_checklist_items')->where('school_id', $schoolId)->count();
        $completed = DB::table('school_setup_checklist_items')->where('school_id', $schoolId)->where('status', 'completed')->count();

        return [
            'status' => $total > 0 && $completed === $total ? 'completed' : 'pending',
            'completed_count' => $completed,
            'total_count' => $total,
            'pending_count' => max(0, $total - $completed),
        ];
    }

    /** @return array<string, mixed>|null */
    private function subscriptionProjection(string $schoolPublicId): ?array
    {
        $subscription = PlatformSubscription::query()->orderByDesc('created_at')->orderByDesc('id')->first();

        if (! $subscription instanceof PlatformSubscription) {
            return null;
        }

        $status = $this->effectiveSubscriptionStatus($subscription);
        $plan = PlatformSubscriptionPlan::query()->whereKey($subscription->platform_subscription_plan_id)->first();

        return [
            'id' => (string) $subscription->public_id,
            'school' => $schoolPublicId,
            'status' => $status,
            'entitlement_status' => in_array($status, ['trial', 'active', 'grace'], true) ? 'available' : 'unavailable',
            'plan' => $plan instanceof PlatformSubscriptionPlan ? [
                'id' => (string) $plan->public_id,
                'code' => (string) $plan->code,
                'version' => (int) $plan->version,
            ] : null,
            'starts_at' => $this->timestamp($subscription->starts_at),
            'ends_at' => $this->timestamp($subscription->ends_at),
        ];
    }

    /** @return array<string, mixed> */
    private function entitlementProjection(string $schoolPublicId): array
    {
        $assignment = PlatformSchoolEntitlement::query()->where('status', 'active')->first();
        if (! $assignment instanceof PlatformSchoolEntitlement) {
            return ['status' => 'unassigned', 'school' => $schoolPublicId, 'plan' => null];
        }

        $plan = PlatformSubscriptionPlan::query()->whereKey($assignment->platform_subscription_plan_id)->first();

        return [
            'id' => (string) $assignment->public_id,
            'status' => (string) $assignment->status,
            'school' => $schoolPublicId,
            'plan' => $plan instanceof PlatformSubscriptionPlan ? [
                'id' => (string) $plan->public_id,
                'code' => (string) $plan->code,
                'version' => (int) $plan->version,
                'entitlement_keys' => array_values(array_map(static fn (mixed $key): string => (string) $key, json_decode(json_encode($plan->entitlement_keys, JSON_THROW_ON_ERROR), true))),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $school
     * @param  array<string, mixed>  $filters
     */
    private function matches(array $school, array $filters): bool
    {
        if (isset($filters['status']) && $school['status'] !== $filters['status']) {
            return false;
        }
        if (isset($filters['setup_status']) && $school['setup']['status'] !== $filters['setup_status']) {
            return false;
        }
        if (isset($filters['subscription_state']) && ($school['subscription']['status'] ?? 'unavailable') !== $filters['subscription_state']) {
            return false;
        }

        return true;
    }

    private function effectiveSubscriptionStatus(PlatformSubscription $subscription): string
    {
        if ($subscription->status === 'cancelled') {
            return 'cancelled';
        }
        if ($subscription->status === 'expired') {
            return 'expired';
        }

        $now = CarbonImmutable::now();
        if ($subscription->trial_ends_at !== null && $now->lessThan(CarbonImmutable::parse((string) $subscription->trial_ends_at))) {
            return 'trial';
        }
        if ($now->lessThan(CarbonImmutable::parse((string) $subscription->ends_at))) {
            return 'active';
        }
        if ($subscription->grace_ends_at !== null && $now->lessThan(CarbonImmutable::parse((string) $subscription->grace_ends_at))) {
            return 'grace';
        }

        return 'expired';
    }

    private function authorize(Authenticatable $actor): void
    {
        $allowed = DB::table('platform_role_assignments')
            ->join('roles', 'roles.id', '=', 'platform_role_assignments.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('platform_role_assignments.user_id', $actor->getAuthIdentifier())
            ->whereNull('platform_role_assignments.revoked_at')
            ->where('roles.scope', 'platform')
            ->where('roles.is_active', true)
            ->where('permissions.scope', 'platform')
            ->where('permissions.is_active', true)
            ->where('permissions.key', 'platform.schools.read')
            ->exists();

        if (! $allowed) {
            throw new ModelNotFoundException;
        }
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
