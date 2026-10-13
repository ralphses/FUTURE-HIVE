<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Finance\Domain\Models\FeeCategory;
use App\Contexts\Finance\Domain\Models\FeePlanLine;
use App\Contexts\Finance\Domain\Models\FeePlanVersion;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FeeConfigurationAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{categories: list<array<string, mixed>>} */
    public function categories(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.read');

        return ['categories' => FeeCategory::query()->where('school_id', TenantContext::require()->schoolId)->orderBy('name')->get()->map(fn (FeeCategory $category): array => $this->categoryData($category))->all()];
    }

    /** @param array{code: string, name: string, description?: string|null} $data
     * @return array{category: array<string, mixed>}
     */
    public function createCategory(UserIdentity $actor, string $school, array $data): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $data): array {
            $this->assertCategoryUnique($context->schoolId, $data['code'], $data['name']);
            $category = FeeCategory::query()->create([
                'school_id' => $context->schoolId,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'active',
            ]);
            $this->record($actor, 'finance.fee_category_created', 'fee_category', (string) $category->public_id, ['to' => 'active']);

            return ['category' => $this->categoryData($category)];
        });
    }

    /** @param array{code?: string, name?: string, description?: string|null} $data
     * @return array{category: array<string, mixed>}
     */
    public function updateCategory(UserIdentity $actor, string $school, string $categoryId, array $data): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.manage');
        $category = $this->category($categoryId, true);
        $this->assertCategoryUnique(TenantContext::require()->schoolId, $data['code'] ?? $category->code, $data['name'] ?? $category->name, $category->getKey());
        $category->update($data);
        $this->record($actor, 'finance.fee_category_updated', 'fee_category', (string) $category->public_id, ['to' => 'active']);

        return ['category' => $this->categoryData($category->fresh())];
    }

    /** @return array{plans: list<array<string, mixed>>} */
    public function plans(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.read');
        $plans = FeePlanVersion::query()->where('school_id', TenantContext::require()->schoolId)->with(['session', 'term', 'level', 'section', 'classArm', 'lines.category'])->orderByDesc('academic_term_id')->orderByDesc('version')->get();

        return ['plans' => $plans->map(fn (FeePlanVersion $plan): array => $this->planData($plan))->all()];
    }

    /** @param array<string, mixed> $data
     * @return array{plan: array<string, mixed>}
     */
    public function createPlan(UserIdentity $actor, string $school, array $data): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.manage');
        [$session, $term, $scope] = $this->parents($data['session_id'], $data['term_id'], $data);
        $this->assertDates($term, $data['effective_from'], $data['effective_to']);
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $session, $term, $scope, $data): array {
            $this->assertLines($context->schoolId, $data['lines']);
            $versionQuery = FeePlanVersion::query()->where('school_id', $context->schoolId)->where('academic_term_id', $term->getKey());
            foreach ($scope as $column => $value) {
                $versionQuery = $value === null ? $versionQuery->whereNull($column) : $versionQuery->where($column, $value);
            }
            $version = ((int) $versionQuery->lockForUpdate()->max('version')) + 1;
            $plan = FeePlanVersion::query()->create([
                'school_id' => $context->schoolId,
                'academic_session_id' => $session->getKey(),
                'academic_term_id' => $term->getKey(),
                ...$scope,
                'version' => $version,
                'name' => $data['name'],
                'currency' => $data['currency'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'],
                'status' => 'draft',
                'created_by' => $actor->getKey(),
            ]);
            $this->replaceLines($plan, $data['lines'], $context->schoolId);
            $this->record($actor, 'finance.fee_plan_created', 'fee_plan_version', (string) $plan->public_id, ['to' => 'draft', 'line_count' => count($data['lines'])]);

            return ['plan' => $this->planData($plan->load(['session', 'term', 'level', 'section', 'classArm', 'lines.category']))];
        });
    }

    /** @return array{plan: array<string, mixed>} */
    public function showPlan(UserIdentity $actor, string $school, string $planId): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.read');

        return ['plan' => $this->planData($this->plan($planId)->load(['session', 'term', 'level', 'section', 'classArm', 'lines.category']))];
    }

    /** @param array<string, mixed> $data
     * @return array{plan: array<string, mixed>}
     */
    public function updatePlan(UserIdentity $actor, string $school, string $planId, array $data): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.manage');
        $plan = $this->plan($planId, true);
        if ($plan->status !== 'draft') {
            throw ValidationException::withMessages(['plan' => ['Only draft fee plans can be edited.']]);
        }
        $term = $plan->term;
        $effectiveFrom = $data['effective_from'] ?? $this->date($plan->effective_from);
        $effectiveTo = $data['effective_to'] ?? $this->date($plan->effective_to);
        $this->assertDates($term, $effectiveFrom, $effectiveTo);

        return DB::transaction(function () use ($actor, $plan, $data, $effectiveFrom, $effectiveTo): array {
            $plan->update(array_filter([...$data, 'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo], static fn (mixed $value, string $key): bool => $key !== 'lines' && $value !== null, ARRAY_FILTER_USE_BOTH));
            if (array_key_exists('lines', $data)) {
                $this->assertLines(TenantContext::require()->schoolId, $data['lines']);
                $plan->lines()->delete();
                $this->replaceLines($plan, $data['lines'], TenantContext::require()->schoolId);
            }
            $this->record($actor, 'finance.fee_plan_updated', 'fee_plan_version', (string) $plan->public_id, ['to' => 'draft', 'line_count' => $plan->lines()->count()]);

            return ['plan' => $this->planData($plan->fresh()->load(['session', 'term', 'level', 'section', 'classArm', 'lines.category']))];
        });
    }

    /** @return array{plan: array<string, mixed>} */
    public function activatePlan(UserIdentity $actor, string $school, string $planId): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.manage');

        return DB::transaction(function () use ($actor, $planId): array {
            $plan = $this->plan($planId, true);
            if ($plan->status === 'active') {
                return ['plan' => $this->planData($plan->load(['session', 'term', 'level', 'section', 'classArm', 'lines.category']))];
            }
            if ($plan->status !== 'draft') {
                throw ValidationException::withMessages(['plan' => ['Only draft fee plans can be activated.']]);
            }
            if ($plan->term->status === 'closed' || $plan->session->status === 'closed') {
                throw ValidationException::withMessages(['plan' => ['Closed academic periods cannot receive fee plans.']]);
            }
            $active = $this->sameScopeQuery($plan)->where('status', 'active')->lockForUpdate()->first();
            if ($active instanceof FeePlanVersion) {
                throw ValidationException::withMessages(['plan' => ['Only one active fee plan is allowed for this scope and term.']]);
            }
            $plan->update(['status' => 'active', 'activated_by' => $actor->getKey(), 'activated_at' => now()]);
            $this->record($actor, 'finance.fee_plan_activated', 'fee_plan_version', (string) $plan->public_id, ['from' => 'draft', 'to' => 'active']);

            return ['plan' => $this->planData($plan->fresh()->load(['session', 'term', 'level', 'section', 'classArm', 'lines.category']))];
        });
    }

    /** @return array{plan: array<string, mixed>} */
    public function retirePlan(UserIdentity $actor, string $school, string $planId): array
    {
        $this->authorize($actor, $school, 'finance.fee-plans.manage');

        return DB::transaction(function () use ($actor, $planId): array {
            $plan = $this->plan($planId, true);
            if ($plan->status === 'retired') {
                return ['plan' => $this->planData($plan->load(['session', 'term', 'level', 'section', 'classArm', 'lines.category']))];
            }
            if ($plan->status !== 'active') {
                throw ValidationException::withMessages(['plan' => ['Only active fee plans can be retired.']]);
            }
            $plan->update(['status' => 'retired', 'retired_by' => $actor->getKey(), 'retired_at' => now()]);
            $this->record($actor, 'finance.fee_plan_retired', 'fee_plan_version', (string) $plan->public_id, ['from' => 'active', 'to' => 'retired']);

            return ['plan' => $this->planData($plan->fresh()->load(['session', 'term', 'level', 'section', 'classArm', 'lines.category']))];
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function category(string $id, bool $lock = false): FeeCategory
    {
        $query = FeeCategory::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function plan(string $id, bool $lock = false): FeePlanVersion
    {
        $query = FeePlanVersion::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->with(['session', 'term', 'level', 'section', 'classArm', 'lines.category'])->firstOrFail();
    }

    /** @param array<string, mixed> $data
     * @return array{0: AcademicSession, 1: AcademicTerm, 2: array<string, int|null>}
     */
    private function parents(string $sessionId, string $termId, array $data): array
    {
        $context = TenantContext::require();
        $session = AcademicSession::query()->where('school_id', $context->schoolId)->where('public_id', $sessionId)->first();
        $term = AcademicTerm::query()->where('school_id', $context->schoolId)->where('academic_session_id', $session?->getKey())->where('public_id', $termId)->first();
        if (! $session instanceof AcademicSession || ! $term instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }
        if ($term->status === 'closed' || $session->status === 'closed') {
            throw ValidationException::withMessages(['term' => ['Closed academic periods cannot receive fee plans.']]);
        }
        $level = $this->optionalParent(AcademicLevel::class, $data['level_id'] ?? null);
        $section = $this->optionalParent(AcademicSection::class, $data['section_id'] ?? null);
        $classArm = $this->optionalParent(AcademicClassArm::class, $data['class_arm_id'] ?? null);
        if ($section instanceof AcademicSection && (! $level instanceof AcademicLevel || $section->academic_level_id !== $level->getKey())) {
            throw ValidationException::withMessages(['section_id' => ['The section must belong to the selected level.']]);
        }
        if ($classArm instanceof AcademicClassArm && (! $level instanceof AcademicLevel || ! $section instanceof AcademicSection || $classArm->academic_level_id !== $level->getKey() || $classArm->academic_section_id !== $section->getKey())) {
            throw ValidationException::withMessages(['class_arm_id' => ['The class arm must belong to the selected level and section.']]);
        }
        foreach ([$level, $section, $classArm] as $parent) {
            if ($parent !== null && $parent->status !== 'active') {
                throw ValidationException::withMessages(['scope' => ['Inactive academic structures cannot receive fee plans.']]);
            }
        }

        return [$session, $term, ['academic_level_id' => $level?->getKey(), 'academic_section_id' => $section?->getKey(), 'academic_class_arm_id' => $classArm?->getKey()]];
    }

    private function optionalParent(string $class, ?string $publicId): ?object
    {
        if ($publicId === null) {
            return null;
        }
        $parent = $class::query()->where('school_id', TenantContext::require()->schoolId)->where('public_id', $publicId)->first();
        if ($parent === null) {
            throw new ModelNotFoundException;
        }

        return $parent;
    }

    private function assertDates(AcademicTerm $term, string $from, string $to): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
        $termStart = CarbonImmutable::parse((string) $term->start_date);
        $termEnd = CarbonImmutable::parse((string) $term->end_date);
        if ($start === null || $end === null || $start->lt($termStart) || $end->gt($termEnd) || $start->gt($end)) {
            throw ValidationException::withMessages(['effective_from' => ['Fee-plan dates must be ordered and contained within the academic term.']]);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function assertLines(int $schoolId, array $lines): void
    {
        $categories = collect($lines)->map(fn (array $line): FeeCategory => $this->category((string) $line['category_id']));
        if ($categories->contains(fn (FeeCategory $category): bool => $category->status !== 'active')) {
            throw ValidationException::withMessages(['lines' => ['Only active fee categories can be added to a plan.']]);
        }
        if ($categories->count() !== $categories->unique(fn (FeeCategory $category): int => $category->getKey())->count() || collect($lines)->pluck('sequence')->unique()->count() !== count($lines)) {
            throw ValidationException::withMessages(['lines' => ['Fee-plan categories and sequence values must be unique.']]);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(FeePlanVersion $plan, array $lines, int $schoolId): void
    {
        foreach ($lines as $line) {
            $category = $this->category((string) $line['category_id']);
            FeePlanLine::query()->create(['school_id' => $schoolId, 'fee_plan_version_id' => $plan->getKey(), 'fee_category_id' => $category->getKey(), 'amount_minor_units' => $line['amount_minor_units'], 'sequence' => $line['sequence'], 'is_required' => (bool) $line['is_required']]);
        }
    }

    private function assertCategoryUnique(int $schoolId, string $code, string $name, ?int $ignoreId = null): void
    {
        $query = FeeCategory::query()->where('school_id', $schoolId)->where(fn ($q) => $q->where('code', $code)->orWhere('name', $name));
        if ($ignoreId !== null) {
            $query->where((new FeeCategory)->getKeyName(), '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['category' => ['Fee category code and name must be unique within the school.']]);
        }
    }

    /** @return Builder<FeePlanVersion> */
    private function sameScopeQuery(FeePlanVersion $plan): Builder
    {
        $query = FeePlanVersion::query()->where('school_id', $plan->school_id)->where('academic_term_id', $plan->academic_term_id);
        foreach (['academic_level_id', 'academic_section_id', 'academic_class_arm_id'] as $column) {
            $query = $plan->{$column} === null ? $query->whereNull($column) : $query->where($column, $plan->{$column});
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function categoryData(FeeCategory $category): array
    {
        return ['id' => (string) $category->public_id, 'code' => (string) $category->code, 'name' => (string) $category->name, 'description' => $category->description, 'status' => (string) $category->status, 'created_at' => $this->timestamp($category->created_at)];
    }

    /** @return array<string, mixed> */
    private function planData(FeePlanVersion $plan): array
    {
        return ['id' => (string) $plan->public_id, 'session_id' => (string) $plan->session?->public_id, 'term_id' => (string) $plan->term?->public_id, 'scope' => ['level_id' => $plan->level?->public_id, 'section_id' => $plan->section?->public_id, 'class_arm_id' => $plan->classArm?->public_id], 'version' => (int) $plan->version, 'name' => (string) $plan->name, 'currency' => (string) $plan->currency, 'effective_from' => $this->date($plan->effective_from), 'effective_to' => $this->date($plan->effective_to), 'status' => (string) $plan->status, 'lines' => $plan->lines->map(fn (FeePlanLine $line): array => ['id' => (string) $line->public_id, 'category_id' => (string) $line->category?->public_id, 'category_name' => (string) $line->category?->name, 'amount_minor_units' => (int) $line->amount_minor_units, 'sequence' => (int) $line->sequence, 'is_required' => (bool) $line->is_required])->all(), 'activated_at' => $this->timestamp($plan->activated_at), 'retired_at' => $this->timestamp($plan->retired_at), 'created_at' => $this->timestamp($plan->created_at)];
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toDateString();
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    /** @param array<string, mixed> $transition */
    private function record(UserIdentity $actor, string $action, string $subjectType, string $subjectId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: $subjectType, subjectPublicId: $subjectId, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
