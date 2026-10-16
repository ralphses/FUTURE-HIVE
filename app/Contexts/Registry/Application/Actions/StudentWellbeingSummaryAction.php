<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\CounsellingCase;
use App\Contexts\Registry\Domain\Models\CounsellingDevelopmentPlan;
use App\Contexts\Registry\Domain\Models\CounsellingReferral;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class StudentWellbeingSummaryAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return array{items: list<array<string, mixed>>, meta: array<string, int>} */
    public function index(UserIdentity $actor, string $school, int $page = 1, int $perPage = 25): array
    {
        $this->authorize($actor, $school);
        $perPage = min(max($perPage, 1), 50);
        $paginator = $this->query($actor)->paginate($perPage, ['*'], 'page', max($page, 1));
        $items = $this->summaries($paginator->getCollection());

        return [
            'items' => $items->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @return array{id: string, school_id: string, student: array{id: string, student_number: string, display_name: string}, case: array{status: string, category: string}, development_plans: list<array{id: string, status: string, progress_state: string, review_date: string|null, version: int}>, referrals: list<array{id: string, type: string, status: string, urgency: string, reason_code: string, destination_scope: string}>, summary_status: string, availability: array{case: string, development_plans: string, referrals: string}, last_reviewed_at: string|null} */
    public function show(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorize($actor, $school);
        $case = $this->query($actor)->whereHas('student', fn (Builder $query): Builder => $query->where('public_id', $student))->first();
        if (! $case instanceof CounsellingCase) {
            throw new ModelNotFoundException;
        }

        return $this->summaries(collect([$case]))->first();
    }

    private function authorize(UserIdentity $actor, string $school): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'counselling.wellbeing-summaries.read')) {
            throw new ModelNotFoundException;
        }
    }

    /** @return Builder<CounsellingCase> */
    private function query(UserIdentity $actor): Builder
    {
        $context = TenantContext::require();
        $query = CounsellingCase::query()
            ->where('school_id', $context->schoolId)
            ->whereHas('assignment', function (Builder $assignment): void {
                $assignment->where('status', 'active')
                    ->whereHas('staffProfile', fn (Builder $staff): Builder => $staff->where('employment_status', 'active'))
                    ->whereHas('membership', function (Builder $membership): void {
                        $membership->where('status', 'active')->whereHas('roleAssignments', fn (Builder $roles): Builder => $roles->whereNull('revoked_at')->whereHas('role', fn (Builder $role): Builder => $role->where('key', 'counsellor')));
                    });
            })
            ->whereHas('student', fn (Builder $student): Builder => $student->where('status', 'active'))
            ->whereHas('enrollment', fn (Builder $enrollment): Builder => $enrollment->where('status', 'active'))
            ->with(['student', 'assignment.staffProfile', 'enrollment'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if (! $this->isSafeguardingLeadership($actor)) {
            $query->whereHas('assignment.membership', fn (Builder $membership): Builder => $membership->where('user_id', $actor->getKey()));
        }

        return $query;
    }

    /**
     * @param  Collection<int, CounsellingCase>  $cases
     * @return Collection<int, array{
     *     id: string,
     *     school_id: string,
     *     student: array{id: string, student_number: string, display_name: string},
     *     case: array{status: string, category: string},
     *     development_plans: list<array{id: string, status: string, progress_state: string, review_date: string|null, version: int}>,
     *     referrals: list<array{id: string, type: string, status: string, urgency: string, reason_code: string, destination_scope: string}>,
     *     summary_status: string,
     *     availability: array{case: string, development_plans: string, referrals: string},
     *     last_reviewed_at: string|null
     * }>
     */
    private function summaries(Collection $cases): Collection
    {
        $caseIds = $cases->map(fn (CounsellingCase $case): int => (int) $case->getKey())->all();
        $plans = CounsellingDevelopmentPlan::query()->where('school_id', TenantContext::require()->schoolId)->whereIn('counselling_case_id', $caseIds)->get()->groupBy('counselling_case_id');
        $referrals = CounsellingReferral::query()->where('school_id', TenantContext::require()->schoolId)->whereIn('counselling_case_id', $caseIds)->get()->groupBy('counselling_case_id');

        return $cases->map(function (CounsellingCase $case) use ($plans, $referrals): array {
            $casePlans = $plans->get($case->getKey(), collect());
            $caseReferrals = $referrals->get($case->getKey(), collect());
            $lastReviewed = collect([$case->updated_at, ...$casePlans->pluck('updated_at')->all(), ...$caseReferrals->pluck('updated_at')->all()])->filter()->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse((string) $value))->sortDesc()->first();

            return [
                'id' => (string) $case->public_id,
                'school_id' => TenantContext::require()->schoolPublicId,
                'student' => [
                    'id' => (string) $case->student?->public_id,
                    'student_number' => (string) $case->student?->student_number,
                    'display_name' => (string) $case->student?->display_name,
                ],
                'case' => [
                    'status' => (string) $case->status,
                    'category' => (string) $case->category,
                ],
                'development_plans' => $this->planData($casePlans),
                'referrals' => $this->referralData($caseReferrals),
                'summary_status' => in_array($case->status, ['open', 'on_hold'], true) ? 'available' : 'limited',
                'availability' => [
                    'case' => 'available',
                    'development_plans' => $casePlans->isEmpty() ? 'not_available' : 'available',
                    'referrals' => $caseReferrals->isEmpty() ? 'not_available' : 'available',
                ],
                'last_reviewed_at' => $lastReviewed?->toISOString(),
            ];
        });
    }

    /** @param Collection<int, CounsellingDevelopmentPlan> $plans
     * @return list<array{id: string, status: string, progress_state: string, review_date: string|null, version: int}>
     */
    private function planData(Collection $plans): array
    {
        return $plans->map(fn (CounsellingDevelopmentPlan $plan): array => [
            'id' => (string) $plan->public_id,
            'status' => (string) $plan->status,
            'progress_state' => (string) $plan->progress_state,
            'review_date' => $plan->review_date === null ? null : CarbonImmutable::parse((string) $plan->review_date)->toDateString(),
            'version' => (int) $plan->version,
        ])->values()->all();
    }

    /** @param Collection<int, CounsellingReferral> $referrals
     * @return list<array{id: string, type: string, status: string, urgency: string, reason_code: string, destination_scope: string}>
     */
    private function referralData(Collection $referrals): array
    {
        return $referrals->map(fn (CounsellingReferral $referral): array => [
            'id' => (string) $referral->public_id,
            'type' => (string) $referral->type,
            'status' => (string) $referral->status,
            'urgency' => (string) $referral->urgency,
            'reason_code' => (string) $referral->reason_code,
            'destination_scope' => (string) $referral->destination_scope,
        ])->values()->all();
    }

    private function isSafeguardingLeadership(UserIdentity $actor): bool
    {
        return SchoolMembership::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereHas('roleAssignments', fn (Builder $assignments): Builder => $assignments->whereNull('revoked_at')->whereHas('role', fn (Builder $role): Builder => $role->whereIn('key', ['school_admin', 'principal'])))
            ->exists();
    }
}
