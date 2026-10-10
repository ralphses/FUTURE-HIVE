<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleCriterion;
use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleSet;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicPromotionRuleAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'academic.promotion-rules.read');

        return ['items' => AcademicPromotionRuleSet::query()->with(['criteria', 'sourceLevel', 'targetLevel'])->orderBy('name')->get()->map(fn (AcademicPromotionRuleSet $rule): array => $this->data($rule))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $rule): array
    {
        $this->authorize($actor, $school, 'academic.promotion-rules.read');

        return $this->data($this->find($rule));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'academic.promotion-rules.manage');

        return DB::transaction(function () use ($actor, $input): array {
            [$source, $target] = $this->levels($input['source_level_id'], $input['target_level_id']);
            $this->validateCriteria($input['criteria']);
            $rule = AcademicPromotionRuleSet::query()->create(['source_academic_level_id' => $source->getKey(), 'target_academic_level_id' => $target->getKey(), 'name' => trim((string) $input['name']), 'description' => isset($input['description']) ? trim((string) $input['description']) : null, 'status' => 'draft']);
            $this->replaceCriteria($rule, $input['criteria']);
            $this->record($actor, 'academic.promotion_rule_created', (string) $rule->public_id, ['status' => 'draft', 'criterion_count' => count($input['criteria'])]);

            return $this->data($rule->load(['criteria', 'sourceLevel', 'targetLevel']));
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $publicId, array $input): array
    {
        $this->authorize($actor, $school, 'academic.promotion-rules.manage');

        return DB::transaction(function () use ($actor, $publicId, $input): array {
            $rule = $this->find($publicId, true);
            if ($rule->status === 'active') {
                throw ValidationException::withMessages(['rule' => ['Active promotion rules must be deactivated before replacement.']]);
            }
            [$source, $target] = $this->levels($input['source_level_id'], $input['target_level_id']);
            $this->validateCriteria($input['criteria']);
            $this->assertActivePairAvailable($source->getKey(), $target->getKey(), $rule->getKey());
            $rule->update(['source_academic_level_id' => $source->getKey(), 'target_academic_level_id' => $target->getKey(), 'name' => trim((string) $input['name']), 'description' => isset($input['description']) ? trim((string) $input['description']) : null]);
            $rule->criteria()->delete();
            $this->replaceCriteria($rule, $input['criteria']);
            $this->record($actor, 'academic.promotion_rule_replaced', (string) $rule->public_id, ['status' => (string) $rule->status, 'criterion_count' => count($input['criteria'])]);

            return $this->data($rule->fresh(['criteria', 'sourceLevel', 'targetLevel']));
        });
    }

    /** @return array<string, mixed> */
    public function activate(UserIdentity $actor, string $school, string $publicId): array
    {
        return $this->transition($actor, $school, $publicId, 'active');
    }

    /** @return array<string, mixed> */
    public function deactivate(UserIdentity $actor, string $school, string $publicId): array
    {
        return $this->transition($actor, $school, $publicId, 'inactive');
    }

    /** @return array<string, mixed> */
    private function transition(UserIdentity $actor, string $school, string $publicId, string $status): array
    {
        $this->authorize($actor, $school, 'academic.promotion-rules.manage');

        return DB::transaction(function () use ($actor, $publicId, $status): array {
            $rule = $this->find($publicId, true);
            if ($rule->status === $status) {
                return $this->data($rule);
            }
            if ($status === 'active') {
                if ($rule->status !== 'draft' && $rule->status !== 'inactive') {
                    throw ValidationException::withMessages(['rule' => ['Only draft or inactive promotion rules can be activated.']]);
                }
                $this->assertActivePairAvailable($rule->source_academic_level_id, $rule->target_academic_level_id, $rule->getKey());
                if ($rule->criteria()->count() < 1) {
                    throw ValidationException::withMessages(['criteria' => ['A promotion rule must have at least one criterion.']]);
                }
            } elseif ($rule->status !== 'active') {
                throw ValidationException::withMessages(['rule' => ['Only active promotion rules can be deactivated.']]);
            }
            $rule->update(['status' => $status]);
            $this->record($actor, 'academic.promotion_rule_'.($status === 'active' ? 'activated' : 'deactivated'), (string) $rule->public_id, ['previous_status' => (string) ($status === 'active' ? 'draft' : 'active'), 'new_status' => $status]);

            return $this->data($rule->fresh(['criteria', 'sourceLevel', 'targetLevel']));
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function find(string $publicId, bool $lock = false): AcademicPromotionRuleSet
    {
        $query = AcademicPromotionRuleSet::query()->where('public_id', $publicId)->with(['criteria', 'sourceLevel', 'targetLevel']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rule = $query->first();
        if (! $rule instanceof AcademicPromotionRuleSet) {
            throw new ModelNotFoundException;
        }

        return $rule;
    }

    /** @return array{0: AcademicLevel, 1: AcademicLevel} */
    private function levels(string $sourceId, string $targetId): array
    {
        if ($sourceId === $targetId) {
            throw ValidationException::withMessages(['target_level_id' => ['The target level must differ from the source level.']]);
        }
        $source = AcademicLevel::query()->where('public_id', $sourceId)->first();
        $target = AcademicLevel::query()->where('public_id', $targetId)->first();
        if (! $source instanceof AcademicLevel || ! $target instanceof AcademicLevel) {
            throw new ModelNotFoundException;
        }

        return [$source, $target];
    }

    /** @param list<array<string, mixed>> $criteria */
    private function validateCriteria(array $criteria): void
    {
        foreach ($criteria as $criterion) {
            $metric = (string) $criterion['metric'];
            $threshold = (float) $criterion['threshold'];
            if ($metric === 'minimum_passing_subjects' && floor($threshold) !== $threshold) {
                throw ValidationException::withMessages(['criteria' => ['The minimum passing subjects threshold must be a whole number.']]);
            }
            if ($metric === 'minimum_passing_subjects' && $threshold > 100) {
                throw ValidationException::withMessages(['criteria' => ['The minimum passing subjects threshold is out of range.']]);
            }
        }
    }

    private function assertActivePairAvailable(int $sourceId, int $targetId, int $exceptId): void
    {
        if (AcademicPromotionRuleSet::query()->where('source_academic_level_id', $sourceId)->where('target_academic_level_id', $targetId)->where('status', 'active')->whereKeyNot($exceptId)->exists()) {
            throw ValidationException::withMessages(['rule' => ['An active promotion rule already exists for this level pair.']]);
        }
    }

    /** @param list<array<string, mixed>> $criteria */
    private function replaceCriteria(AcademicPromotionRuleSet $rule, array $criteria): void
    {
        foreach ($criteria as $criterion) {
            AcademicPromotionRuleCriterion::query()->create(['academic_promotion_rule_set_id' => $rule->getKey(), 'metric' => (string) $criterion['metric'], 'operator' => (string) $criterion['operator'], 'threshold' => $criterion['threshold'], 'is_required' => (bool) $criterion['required'], 'sequence' => (int) $criterion['sequence']]);
        }
    }

    /** @param array<string, int|string> $transition */
    private function record(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_promotion_rule_set', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.promotion-rules.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(AcademicPromotionRuleSet $rule): array
    {
        return ['id' => (string) $rule->public_id, 'source_level_id' => (string) $rule->sourceLevel->public_id, 'target_level_id' => (string) $rule->targetLevel->public_id, 'name' => (string) $rule->name, 'description' => $rule->description === null ? null : (string) $rule->description, 'status' => (string) $rule->status, 'criteria' => $rule->criteria->map(fn (AcademicPromotionRuleCriterion $criterion): array => ['id' => (string) $criterion->public_id, 'metric' => (string) $criterion->metric, 'operator' => (string) $criterion->operator, 'threshold' => (string) $criterion->threshold, 'required' => (bool) $criterion->is_required, 'sequence' => (int) $criterion->sequence])->values()->all()];
    }
}
