<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleSet;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Exceptions\PromotionCycleInvalid;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Contexts\Registry\Domain\Models\StudentEnrollmentChange;
use App\Contexts\Registry\Domain\Models\StudentPromotionCycle;
use App\Contexts\Registry\Domain\Models\StudentPromotionDecision;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StudentPromotionAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'students.promotion.read');

        return ['items' => StudentPromotionCycle::query()->with(['sourceTerm', 'targetTerm', 'decisions'])->latest('id')->get()->map(fn (StudentPromotionCycle $cycle): array => $this->cycleData($cycle))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $cycleId): array
    {
        $this->authorize($actor, $school, 'students.promotion.read');

        return $this->cycleData($this->cycle($cycleId));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'students.promotion.manage');

        return DB::transaction(function () use ($actor, $input): array {
            $source = $this->term($input['source_term_id'], true);
            $targetSession = $this->session($input['target_session_id'], true);
            $target = $this->term($input['target_term_id'], true);
            $this->assertPeriodPair($source, $targetSession, $target);
            $rule = null;
            if (! empty($input['promotion_rule_id'])) {
                $rule = AcademicPromotionRuleSet::query()->where('public_id', $input['promotion_rule_id'])->lockForUpdate()->first();
                if (! $rule instanceof AcademicPromotionRuleSet || $rule->status !== 'active') {
                    throw new ModelNotFoundException;
                }
            }
            $cycle = StudentPromotionCycle::query()->create(['source_academic_term_id' => $source->getKey(), 'target_academic_session_id' => $targetSession->getKey(), 'target_academic_term_id' => $target->getKey(), 'academic_promotion_rule_set_id' => $rule?->getKey(), 'status' => 'draft', 'created_by' => $actor->getAuthIdentifier(), 'reason' => isset($input['reason']) ? trim((string) $input['reason']) : null]);
            $this->record($actor, 'student.promotion_cycle_created', (string) $cycle->public_id, ['status' => 'draft']);

            return $this->cycleData($cycle->load(['sourceTerm', 'targetTerm', 'decisions']));
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addDecision(UserIdentity $actor, string $school, string $cycleId, array $input): array
    {
        $this->authorize($actor, $school, 'students.promotion.manage');

        return DB::transaction(function () use ($actor, $cycleId, $input): array {
            $cycle = $this->cycle($cycleId, true);
            if (! in_array($cycle->status, ['draft', 'proposed'], true)) {
                throw PromotionCycleInvalid::make('Only draft or proposed cycles accept decisions.');
            }
            $student = Student::query()->where('public_id', $input['student_id'])->lockForUpdate()->first();
            $sourceEnrollment = StudentEnrollment::query()->where('public_id', $input['source_enrollment_id'])->lockForUpdate()->first();
            $targetLevel = $this->level($input['target_level_id'], true);
            $targetClassArm = $this->classArm($input['target_class_arm_id'], true);
            if (! $student instanceof Student || ! $sourceEnrollment instanceof StudentEnrollment || $student->status !== 'active' || $sourceEnrollment->status !== 'active') {
                throw new ModelNotFoundException;
            }
            if ((int) $sourceEnrollment->student_id !== (int) $student->getKey() || (int) $sourceEnrollment->academic_term_id !== (int) $cycle->source_academic_term_id || (int) $targetClassArm->academic_level_id !== (int) $targetLevel->getKey() || $targetLevel->status !== 'active' || $targetClassArm->status !== 'active' || $targetClassArm->section->status !== 'active') {
                throw new ModelNotFoundException;
            }
            $expectedLevel = $input['decision'] === 'repeat' ? $sourceEnrollment->academic_level_id : $cycle->promotionRule?->target_academic_level_id;
            if ($expectedLevel === null || (int) $expectedLevel !== (int) $targetLevel->getKey()) {
                throw PromotionCycleInvalid::make('The target level does not match the selected decision.');
            }
            if (StudentPromotionDecision::query()->where('student_promotion_cycle_id', $cycle->getKey())->where('student_id', $student->getKey())->exists()) {
                throw PromotionCycleInvalid::make('A decision already exists for this student in the cycle.');
            }
            $decision = StudentPromotionDecision::query()->create(['student_promotion_cycle_id' => $cycle->getKey(), 'student_id' => $student->getKey(), 'source_enrollment_id' => $sourceEnrollment->getKey(), 'target_academic_level_id' => $targetLevel->getKey(), 'target_academic_class_arm_id' => $targetClassArm->getKey(), 'decision' => $input['decision'], 'status' => 'proposed', 'reason' => isset($input['reason']) ? trim((string) $input['reason']) : null, 'created_by' => $actor->getAuthIdentifier()]);
            $cycle->update(['status' => 'proposed']);
            $this->record($actor, 'student.promotion_decision_created', (string) $decision->public_id, ['status' => 'proposed', 'decision' => (string) $decision->decision]);

            return $this->decisionData($decision->load(['student', 'targetLevel', 'targetClassArm']));
        });
    }

    /** @return array<string, mixed> */
    public function approve(UserIdentity $actor, string $school, string $cycleId): array
    {
        $this->authorize($actor, $school, 'students.promotion.approve');

        return DB::transaction(function () use ($actor, $cycleId): array {
            $cycle = $this->cycle($cycleId, true);
            if ($cycle->status === 'approved') {
                return $this->cycleData($cycle);
            }
            if ($cycle->status !== 'proposed' || ! $cycle->decisions()->exists()) {
                throw PromotionCycleInvalid::make('A proposed cycle with decisions is required for approval.');
            }
            $cycle->decisions()->where('status', 'proposed')->update(['status' => 'approved', 'approved_by' => $actor->getAuthIdentifier(), 'approved_at' => now()]);
            $cycle->update(['status' => 'approved', 'approved_by' => $actor->getAuthIdentifier(), 'approved_at' => now()]);
            $this->record($actor, 'student.promotion_cycle_approved', (string) $cycle->public_id, ['status' => 'approved']);

            return $this->cycleData($cycle->fresh(['sourceTerm', 'targetTerm', 'decisions']));
        });
    }

    /** @return array<string, mixed> */
    public function apply(UserIdentity $actor, string $school, string $cycleId): array
    {
        $this->authorize($actor, $school, 'students.promotion.apply');

        return DB::transaction(function () use ($actor, $cycleId): array {
            $cycle = $this->cycle($cycleId, true);
            if ($cycle->status === 'applied') {
                return $this->cycleData($cycle->fresh(['decisions']));
            }
            if ($cycle->status !== 'approved') {
                throw PromotionCycleInvalid::make('Only an approved cycle can be applied.');
            }
            foreach ($cycle->decisions()->lockForUpdate()->get() as $decision) {
                if ($decision->status === 'applied') {
                    continue;
                }
                $source = StudentEnrollment::query()->whereKey($decision->source_enrollment_id)->lockForUpdate()->first();
                $targetArm = $this->classArmById((int) $decision->target_academic_class_arm_id, true);
                if (! $source instanceof StudentEnrollment || $source->status !== 'active' || $targetArm->status !== 'active' || $targetArm->section->status !== 'active' || $targetArm->level->status !== 'active') {
                    throw PromotionCycleInvalid::make('A decision is no longer applicable.');
                }
                if (StudentEnrollment::query()->where('academic_class_arm_id', $targetArm->getKey())->where('academic_term_id', $cycle->target_academic_term_id)->where('status', 'active')->count() >= $targetArm->capacity) {
                    throw PromotionCycleInvalid::make('A target class arm has reached capacity.');
                }
                $source->update(['status' => 'ended', 'end_date' => $cycle->targetTerm->start_date, 'ended_by' => $actor->getAuthIdentifier(), 'end_reason' => 'Promotion cycle applied']);
                $replacement = StudentEnrollment::query()->create(['student_id' => $decision->student_id, 'academic_session_id' => $cycle->target_academic_session_id, 'academic_term_id' => $cycle->target_academic_term_id, 'academic_level_id' => $decision->target_academic_level_id, 'academic_section_id' => $targetArm->academic_section_id, 'academic_class_arm_id' => $targetArm->getKey(), 'start_date' => $cycle->targetTerm->start_date, 'status' => 'active', 'created_by' => $actor->getAuthIdentifier()]);
                StudentEnrollmentChange::query()->create(['student_id' => $decision->student_id, 'enrollment_id' => $source->getKey(), 'replacement_enrollment_id' => $replacement->getKey(), 'change_type' => 'transfer', 'effective_date' => $cycle->targetTerm->start_date, 'reason' => 'Promotion cycle applied', 'created_by' => $actor->getAuthIdentifier()]);
                $decision->update(['status' => 'applied', 'applied_by' => $actor->getAuthIdentifier(), 'applied_at' => now()]);
            }
            $cycle->update(['status' => 'applied', 'applied_by' => $actor->getAuthIdentifier(), 'applied_at' => now()]);
            $this->record($actor, 'student.promotion_cycle_applied', (string) $cycle->public_id, ['status' => 'applied']);

            return $this->cycleData($cycle->fresh(['sourceTerm', 'targetTerm', 'decisions']));
        });
    }

    /** @return array<string, mixed> */
    public function rollback(UserIdentity $actor, string $school, string $cycleId): array
    {
        $this->authorize($actor, $school, 'students.promotion.apply');

        return DB::transaction(function () use ($actor, $cycleId): array {
            $cycle = $this->cycle($cycleId, true);
            if ($cycle->status === 'rolled_back') {
                return $this->cycleData($cycle);
            }
            if ($cycle->status !== 'applied') {
                throw PromotionCycleInvalid::make('Only an applied cycle can be rolled back.');
            }
            if (Schema::hasTable('academic_results') && DB::table('academic_results')->where('school_id', TenantContext::require()->schoolId)->exists()) {
                throw PromotionCycleInvalid::make('Rollback is unavailable after academic results exist.');
            }
            foreach ($cycle->decisions()->where('status', 'applied')->lockForUpdate()->get() as $decision) {
                $source = StudentEnrollment::query()->whereKey($decision->source_enrollment_id)->lockForUpdate()->first();
                $replacement = StudentEnrollment::query()->where('student_id', $decision->student_id)->where('academic_term_id', $cycle->target_academic_term_id)->where('status', 'active')->lockForUpdate()->first();
                if ($source instanceof StudentEnrollment) {
                    $source->update(['status' => 'active', 'end_date' => null, 'ended_by' => null, 'end_reason' => null]);
                }
                if ($replacement instanceof StudentEnrollment) {
                    $replacement->update(['status' => 'ended', 'end_date' => now()->toDateString(), 'ended_by' => $actor->getAuthIdentifier(), 'end_reason' => 'Promotion cycle rolled back']);
                }
                $decision->update(['status' => 'rolled_back', 'rolled_back_by' => $actor->getAuthIdentifier(), 'rolled_back_at' => now()]);
            }
            $cycle->update(['status' => 'rolled_back', 'rolled_back_by' => $actor->getAuthIdentifier(), 'rolled_back_at' => now()]);
            $this->record($actor, 'student.promotion_cycle_rolled_back', (string) $cycle->public_id, ['status' => 'rolled_back']);

            return $this->cycleData($cycle->fresh(['decisions']));
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function cycle(string $id, bool $lock = false): StudentPromotionCycle
    {
        $q = StudentPromotionCycle::query()->where('public_id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $cycle = $q->first();
        if (! $cycle instanceof StudentPromotionCycle) {
            throw new ModelNotFoundException;
        }

        return $cycle;
    }

    private function term(string $id, bool $lock = false): AcademicTerm
    {
        $q = AcademicTerm::query()->where('public_id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $term = $q->first();
        if (! $term instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return $term;
    }

    private function session(string $id, bool $lock = false): AcademicSession
    {
        $q = AcademicSession::query()->where('public_id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $session = $q->first();
        if (! $session instanceof AcademicSession) {
            throw new ModelNotFoundException;
        }

        return $session;
    }

    private function level(string $id, bool $lock = false): AcademicLevel
    {
        $q = AcademicLevel::query()->where('public_id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $level = $q->first();
        if (! $level instanceof AcademicLevel) {
            throw new ModelNotFoundException;
        }

        return $level;
    }

    private function classArm(string $id, bool $lock = false): AcademicClassArm
    {
        $q = AcademicClassArm::query()->where('public_id', $id)->with(['section', 'level']);
        if ($lock) {
            $q->lockForUpdate();
        }
        $arm = $q->first();
        if (! $arm instanceof AcademicClassArm) {
            throw new ModelNotFoundException;
        }

        return $arm;
    }

    private function classArmById(int $id, bool $lock = false): AcademicClassArm
    {
        $q = AcademicClassArm::query()->whereKey($id)->with(['section', 'level']);
        if ($lock) {
            $q->lockForUpdate();
        }
        $arm = $q->first();
        if (! $arm instanceof AcademicClassArm) {
            throw new ModelNotFoundException;
        }

        return $arm;
    }

    private function assertPeriodPair(AcademicTerm $source, AcademicSession $targetSession, AcademicTerm $target): void
    {
        if ((int) $target->academic_session_id !== (int) $targetSession->getKey() || $source->status === 'closed' || $target->status === 'closed' || $source->school_id !== $target->school_id || $target->school_id !== $targetSession->school_id) {
            throw new ModelNotFoundException;
        }
    }

    /** @param array<string, int|string> $transition */
    private function record(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'student_promotion_cycle', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'students.promotion.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function cycleData(StudentPromotionCycle $cycle): array
    {
        return ['id' => (string) $cycle->public_id, 'source_term_id' => (string) $cycle->sourceTerm?->public_id, 'target_term_id' => (string) $cycle->targetTerm?->public_id, 'status' => (string) $cycle->status, 'decisions' => $cycle->decisions->map(fn (StudentPromotionDecision $decision): array => $this->decisionData($decision))->values()->all()];
    }

    /** @return array<string, mixed> */
    private function decisionData(StudentPromotionDecision $decision): array
    {
        return ['id' => (string) $decision->public_id, 'student_id' => (string) $decision->student?->public_id, 'decision' => (string) $decision->decision, 'target_level_id' => (string) $decision->targetLevel?->public_id, 'target_class_arm_id' => (string) $decision->targetClassArm?->public_id, 'status' => (string) $decision->status];
    }
}
