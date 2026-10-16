<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\PlatformPermissionResolver;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PlatformOperationalAnalyticsAction
{
    public function __construct(private readonly PlatformPermissionResolver $permissions) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(Authenticatable $actor, array $filters): array
    {
        $this->authorize($actor);
        [$from, $to] = $this->period($filters);

        $schools = DB::table('schools')->select(['id', 'status', 'updated_at'])->get();
        $subscriptions = $this->subscriptionStatuses($schools->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $setup = $this->setupSummary($schools->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $support = DB::table('platform_support_cases')
            ->select(['status', DB::raw('count(*) as total')])
            ->groupBy('status')->pluck('total', 'status')->map(fn (mixed $value): int => (int) $value)->all();
        $activeAccess = DB::table('platform_support_access_grants')
            ->where('status', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->count();

        $activity = DB::table('audit_events')->whereBetween('created_at', [$from, $to])->count();

        $subscriptionCounts = collect($subscriptions)->countBy();
        if ($subscriptionCounts->sum() < $schools->count()) {
            $subscriptionCounts = $subscriptionCounts->put('unavailable', $schools->count() - $subscriptionCounts->sum());
        }

        return [
            'report' => [
                'id' => 'platform-operational-analytics',
                'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()],
                'updated_at' => $schools->max('updated_at') === null ? null : CarbonImmutable::parse((string) $schools->max('updated_at'))->toISOString(),
            ],
            'schools' => [
                'total' => $schools->count(),
                'by_status' => $schools->groupBy('status')->map->count()->sortKeys()->all(),
                'active_context_available' => $this->activeContextSchoolCount($schools),
            ],
            'subscriptions' => ['by_status' => $subscriptionCounts->sortKeys()->all()],
            'entitlements' => ['active_school_count' => DB::table('platform_school_entitlements')->where('status', 'active')->distinct('school_id')->count('school_id')],
            'setup' => $setup,
            'support' => [
                'cases_by_status' => $support,
                'active_access_grant_count' => $activeAccess,
            ],
            'activity' => ['audit_event_count' => $activity],
            'issues' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function schools(Authenticatable $actor, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor);
        [$from, $to] = $this->period($filters);
        $query = DB::table('schools')->select(['id', 'public_id', 'name', 'status', 'created_at', 'updated_at']);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $items = $query->orderBy('name')->orderBy('id')->get()->map(fn (object $school): array => $this->schoolProjection($school, $from, $to));
        if (isset($filters['subscription_state'])) {
            $items = $items->filter(fn (array $school): bool => $school['subscription']['status'] === $filters['subscription_state'])->values();
        }
        if (isset($filters['setup_status'])) {
            $items = $items->filter(fn (array $school): bool => $school['setup']['status'] === $filters['setup_status'])->values();
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = ApiResponse::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null);

        return new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $filters): array
    {
        $to = isset($filters['period_end']) ? CarbonImmutable::parse((string) $filters['period_end'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = isset($filters['period_start']) ? CarbonImmutable::parse((string) $filters['period_start'])->startOfDay() : $to->subDays(30)->startOfDay();
        if ($from->greaterThan($to) || $from->diffInDays($to) > 366) {
            throw new ModelNotFoundException;
        }

        return [$from, $to];
    }

    /**
     * @param  list<int>  $schoolIds
     * @return array<int, string>
     */
    private function subscriptionStatuses(array $schoolIds): array
    {
        return DB::table('platform_subscriptions')->whereIn('school_id', $schoolIds)->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('school_id')->mapWithKeys(function ($records, $schoolId): array {
            $record = $records->first();
            $status = (string) $record->status;
            if (in_array($status, ['trial', 'active'], true) && CarbonImmutable::parse((string) $record->ends_at)->isPast()) {
                $status = $record->grace_ends_at !== null && CarbonImmutable::parse((string) $record->grace_ends_at)->isFuture() ? 'grace' : 'expired';
            }

            return [(int) $schoolId => $status];
        })->all();
    }

    /**
     * @param  list<int>  $schoolIds
     * @return array<string, int>
     */
    private function setupSummary(array $schoolIds): array
    {
        $rows = DB::table('school_setup_checklist_items')->whereIn('school_id', $schoolIds)->get();
        $completed = $rows->where('status', 'completed')->count();

        return ['completed_items' => $completed, 'pending_items' => max(0, $rows->count() - $completed), 'schools_with_complete_setup' => $rows->groupBy('school_id')->filter(fn ($items): bool => $items->every(fn (object $item): bool => $item->status === 'completed'))->count()];
    }

    /** @param Collection<int, \stdClass> $schools */
    private function activeContextSchoolCount(Collection $schools): int
    {
        $sessionSchools = DB::table('academic_sessions')->where('status', 'active')->pluck('school_id')->unique();
        $termSchools = DB::table('academic_terms')->where('status', 'active')->pluck('school_id')->unique();

        return $schools->whereIn('id', $sessionSchools->intersect($termSchools)->all())->count();
    }

    /** @return array<string, mixed> */
    private function schoolProjection(object $school, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $schoolId = (int) $school->id;
        $subscription = DB::table('platform_subscriptions')->where('school_id', $schoolId)->orderByDesc('created_at')->orderByDesc('id')->first();
        $subscriptionStatus = $subscription === null ? 'unavailable' : (string) $subscription->status;
        if ($subscription !== null && in_array($subscriptionStatus, ['trial', 'active'], true) && CarbonImmutable::parse((string) $subscription->ends_at)->isPast()) {
            $subscriptionStatus = $subscription->grace_ends_at !== null && CarbonImmutable::parse((string) $subscription->grace_ends_at)->isFuture() ? 'grace' : 'expired';
        }
        $setup = DB::table('school_setup_checklist_items')->where('school_id', $schoolId)->get();
        $completed = $setup->where('status', 'completed')->count();
        $activeSession = DB::table('academic_sessions')->where('school_id', $schoolId)->where('status', 'active')->exists();
        $activeTerm = DB::table('academic_terms')->where('school_id', $schoolId)->where('status', 'active')->exists();
        $supportCases = DB::table('platform_support_cases')->where('school_id', $schoolId)->select(['status', DB::raw('count(*) as total')])->groupBy('status')->pluck('total', 'status')->map(fn (mixed $value): int => (int) $value)->all();
        $activity = DB::table('audit_events')->where('school_id', $schoolId)->whereBetween('created_at', [$from, $to])->count();

        return [
            'school' => ['id' => (string) $school->public_id, 'name' => (string) $school->name, 'status' => (string) $school->status],
            'academic_context' => ['status' => $activeSession && $activeTerm ? 'available' : 'unavailable'],
            'subscription' => ['status' => $subscriptionStatus],
            'entitlement' => ['status' => DB::table('platform_school_entitlements')->where('school_id', $schoolId)->where('status', 'active')->exists() ? 'active' : 'unassigned'],
            'setup' => ['status' => $setup->isNotEmpty() && $completed === $setup->count() ? 'completed' : 'pending', 'completed_count' => $completed, 'total_count' => $setup->count()],
            'support' => ['cases_by_status' => $supportCases, 'active_access_grant_count' => DB::table('platform_support_access_grants')->where('school_id', $schoolId)->where('status', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->count()],
            'activity' => ['audit_event_count' => $activity],
            'timestamps' => ['created_at' => CarbonImmutable::parse((string) $school->created_at)->toISOString(), 'updated_at' => CarbonImmutable::parse((string) $school->updated_at)->toISOString()],
        ];
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $this->permissions->allows($actor, 'platform.analytics.read')) {
            throw new ModelNotFoundException;
        }
    }
}
