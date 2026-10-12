<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAttendanceEntry;
use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleCriterion;
use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleSet;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class AcademicPromotionEligibilityAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor, string $school, string $session, string $term): LengthAwarePaginator
    {
        $academicTerm = $this->authorizeTerm($actor, $school, $session, $term);
        $rules = AcademicPromotionRuleSet::query()->where('status', 'active')->whereHas('sourceLevel', fn (Builder $query): Builder => $query->where('status', 'active'))->whereHas('targetLevel', fn (Builder $query): Builder => $query->where('status', 'active'))->with(['criteria', 'sourceLevel', 'targetLevel'])->get()->keyBy('source_academic_level_id');
        $enrollments = StudentEnrollment::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('status', 'active')
            ->whereHas('student', fn (Builder $query): Builder => $query->where('status', 'active'))
            ->with(['student', 'level'])
            ->orderBy('student_id')
            ->paginate(ApiResponse::perPage(request()->has('per_page') ? request()->integer('per_page') : null));

        return $enrollments->through(fn (StudentEnrollment $enrollment): array => $this->summary($enrollment, $rules->get($enrollment->academic_level_id), $academicTerm));
    }

    /** @return array<string, mixed> */
    public function student(UserIdentity $actor, string $school, string $session, string $term, string $student): array
    {
        $academicTerm = $this->authorizeTerm($actor, $school, $session, $term);
        $enrollment = StudentEnrollment::query()->where('academic_term_id', $academicTerm->getKey())->where('status', 'active')->whereHas('student', fn (Builder $query): Builder => $query->where('public_id', $student)->where('status', 'active'))->with(['student', 'level'])->first();
        if (! $enrollment instanceof StudentEnrollment) {
            throw new ModelNotFoundException;
        }
        $rule = AcademicPromotionRuleSet::query()->where('status', 'active')->where('source_academic_level_id', $enrollment->academic_level_id)->whereHas('sourceLevel', fn (Builder $query): Builder => $query->where('status', 'active'))->whereHas('targetLevel', fn (Builder $query): Builder => $query->where('status', 'active'))->with(['criteria', 'sourceLevel', 'targetLevel'])->first();

        return $this->summary($enrollment, $rule, $academicTerm);
    }

    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term): AcademicTerm
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'academic.results.promotion-eligibility.read')) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->where('status', 'active')->whereHas('session', fn (Builder $query): Builder => $query->where('school_id', $context->schoolId)->where('public_id', $session)->where('status', 'active'))->with('session')->first();
        if (! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return $academicTerm;
    }

    /** @return array<string, mixed> */
    private function summary(StudentEnrollment $enrollment, ?AcademicPromotionRuleSet $rule, AcademicTerm $term): array
    {
        $student = $enrollment->student;
        $base = [
            'student_id' => (string) $student?->public_id,
            'student_name' => (string) $student?->display_name,
            'enrollment_id' => (string) $enrollment->public_id,
            'source_level_id' => (string) $enrollment->level?->public_id,
            'source_level_name' => (string) $enrollment->level?->name,
            'target_level_id' => (string) $rule?->targetLevel?->public_id,
            'target_level_name' => (string) $rule?->targetLevel?->name,
            'rule_id' => $rule instanceof AcademicPromotionRuleSet ? (string) $rule->public_id : null,
            'status' => 'unavailable',
            'criteria' => [],
            'error_code' => null,
        ];
        if (! $rule instanceof AcademicPromotionRuleSet) {
            $base['error_code'] = 'PROMOTION_RULE_UNAVAILABLE';

            return $base;
        }
        $snapshot = $this->currentSnapshot($term, $student);
        if (! $snapshot instanceof AcademicResultSnapshot) {
            $base['error_code'] = 'PUBLISHED_SNAPSHOT_UNAVAILABLE';

            return $base;
        }
        $entries = $snapshot->entries()->where('student_id', $student->getKey())->whereNull('error_code')->where('status', 'computed')->get();
        $criteria = $rule->criteria->map(fn (AcademicPromotionRuleCriterion $criterion): array => $this->criterion($criterion, $entries, $enrollment, $term))->values()->all();
        $base['criteria'] = $criteria;
        $base['status'] = $criteria === [] ? 'not_computable' : (collect($criteria)->contains(fn (array $criterion): bool => $criterion['status'] === 'not_computable') ? 'not_computable' : (collect($criteria)->every(fn (array $criterion): bool => $criterion['status'] === 'met') ? 'eligible' : 'ineligible'));
        $base['error_code'] = $base['status'] === 'not_computable' ? 'PROMOTION_CRITERION_NOT_COMPUTABLE' : null;

        return $base;
    }

    private function currentSnapshot(AcademicTerm $term, ?Student $student): ?AcademicResultSnapshot
    {
        if (! $student instanceof Student) {
            return null;
        }

        return AcademicResultSnapshot::query()->where('academic_session_id', $term->academic_session_id)->where('academic_term_id', $term->getKey())->where('status', 'published')->whereHas('gate', fn (Builder $query): Builder => $query->where('status', 'published'))->whereHas('entries', fn (Builder $query): Builder => $query->where('student_id', $student->getKey()))->whereDoesntHave('supersedingSnapshots', fn (Builder $query): Builder => $query->where('status', 'published'))->orderByDesc('snapshot_version')->orderByDesc('id')->first();
    }

    /**
     * @param  Collection<int, AcademicResultSnapshotEntry>  $entries
     * @return array{key: string, operator: string, threshold: float, required: bool, status: string, error_code: ?string}
     */
    private function criterion(AcademicPromotionRuleCriterion $criterion, Collection $entries, StudentEnrollment $enrollment, AcademicTerm $term): array
    {
        $value = match ($criterion->metric) {
            'overall_percentage' => $entries->isEmpty() ? null : (float) $entries->avg('percentage'),
            'minimum_passing_subjects' => $entries->isEmpty() ? null : (float) $entries->where('is_passing', true)->count(),
            'attendance_percentage' => $this->attendancePercentage($enrollment, $term),
            default => null,
        };
        $computable = $value !== null;

        return ['key' => (string) $criterion->metric, 'operator' => (string) $criterion->operator, 'threshold' => (float) $criterion->threshold, 'required' => (bool) $criterion->is_required, 'status' => ! $computable ? 'not_computable' : ($value >= (float) $criterion->threshold ? 'met' : 'not_met'), 'error_code' => $computable ? null : 'PROMOTION_INPUT_UNAVAILABLE'];
    }

    private function attendancePercentage(StudentEnrollment $enrollment, AcademicTerm $term): ?float
    {
        $entries = AcademicAttendanceEntry::query()->where('student_id', $enrollment->student_id)->whereHas('register', fn (Builder $query): Builder => $query->where('academic_term_id', $term->getKey())->whereIn('status', ['submitted', 'locked']))->get();
        $recorded = $entries->whereIn('status', ['present', 'absent', 'late', 'excused']);
        if ($recorded->isEmpty()) {
            return null;
        }

        return round(($recorded->whereIn('status', ['present', 'late'])->count() / $recorded->count()) * 100, 2);
    }
}
