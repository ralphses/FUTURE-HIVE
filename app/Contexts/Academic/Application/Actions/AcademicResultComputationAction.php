<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreEntry;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicResultEntry;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicResultComputationAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly AcademicResultCompletenessAction $completeness) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.read');
        $items = AcademicResultRun::query()->where('academic_term_id', $academicTerm->getKey())->latest('id')->get()->map(fn (AcademicResultRun $run): array => $this->runData($run))->all();

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $runId): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.read');
        $run = AcademicResultRun::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $runId)->firstOrFail();
        $entries = $this->visibleEntries($actor, $run, $academicTerm)->get()->map(fn (AcademicResultEntry $entry): array => $this->entryData($entry))->values()->all();

        return ['run' => $this->runData($run), 'entries' => $entries];
    }

    /** @return array<string, mixed> */
    public function student(UserIdentity $actor, string $school, string $session, string $term, string $runId, string $studentId): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.read');
        $run = AcademicResultRun::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $runId)->firstOrFail();
        $entry = $this->visibleEntries($actor, $run, $academicTerm)->whereHas('student', fn ($query) => $query->where('public_id', $studentId))->get()->map(fn (AcademicResultEntry $item): array => $this->entryData($item))->values()->all();

        if ($entry === []) {
            throw new ModelNotFoundException;
        }

        return ['run' => $this->runData($run), 'entries' => $entry];
    }

    /** @return array<string, mixed> */
    public function compute(UserIdentity $actor, string $school, string $session, string $term): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.compute');
        $this->assertLeadership($actor);
        $this->completeness->assertForComputation($academicTerm);

        return DB::transaction(function () use ($actor, $academicTerm): array {
            $sources = AcademicAssessmentSubmission::query()->where('academic_term_id', $academicTerm->getKey())->where('status', 'approved')->whereHas('instance.gradingScale', fn ($query) => $query->where('status', 'active'))->with(['instance.policy.components', 'instance.gradingScale.bands', 'instance.offering'])->get();
            if ($sources->isEmpty()) {
                throw ValidationException::withMessages(['result_run' => ['Approved assessment submissions are required before computing results.']]);
            }
            $activeStudentsByInstance = [];
            $tokens = [];
            foreach ($sources as $submission) {
                $instance = $submission->instance;
                $assessmentDate = CarbonImmutable::parse($instance->assessment_date);
                $activeStudentsByInstance[$instance->getKey()] = StudentEnrollment::query()->where('academic_term_id', $instance->academic_term_id)->where('academic_class_arm_id', $instance->offering->academic_class_arm_id)->where('status', 'active')->whereDate('start_date', '<=', $assessmentDate->toDateString())->where(function ($query) use ($assessmentDate): void {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>=', $assessmentDate->toDateString());
                })->pluck('student_id')->map(fn ($studentId): int => (int) $studentId)->all();
                $tokens = [...$tokens, ...AcademicAssessmentScoreEntry::query()->where('academic_assessment_instance_id', $instance->getKey())->whereIn('student_id', $activeStudentsByInstance[$instance->getKey()])->orderBy('student_id')->orderBy('academic_assessment_policy_component_id')->get(['student_id', 'academic_assessment_policy_component_id', 'revision', 'status', 'score'])->map(fn (AcademicAssessmentScoreEntry $entry): array => [$instance->getKey(), $entry->student_id, $entry->academic_assessment_policy_component_id, $entry->revision, $entry->status, (string) $entry->score])->all()];
            }
            $fingerprint = hash('sha256', json_encode([$academicTerm->getKey(), $tokens], JSON_THROW_ON_ERROR));
            $existing = AcademicResultRun::query()->where('academic_term_id', $academicTerm->getKey())->where('source_fingerprint', $fingerprint)->first();
            if ($existing instanceof AcademicResultRun) {
                return $this->showRun($existing);
            }

            $groups = [];
            foreach ($sources as $submission) {
                $instance = $submission->instance;
                $entries = AcademicAssessmentScoreEntry::query()->where('academic_assessment_instance_id', $instance->getKey())->whereIn('student_id', $activeStudentsByInstance[$instance->getKey()] ?? [])->with(['student', 'component'])->get()->groupBy('student_id');
                foreach ($entries as $studentId => $scoreEntries) {
                    $key = $studentId.':'.$instance->academic_subject_offering_id;
                    $groups[$key] ??= ['student_id' => $studentId, 'offering_id' => $instance->academic_subject_offering_id, 'session_id' => $instance->academic_session_id, 'items' => []];
                    $groups[$key]['items'][] = ['instance' => $instance, 'entries' => $scoreEntries];
                }
            }

            $run = AcademicResultRun::query()->create(['school_id' => TenantContext::require()->schoolId, 'academic_session_id' => $academicTerm->academic_session_id, 'academic_term_id' => $academicTerm->getKey(), 'status' => 'completed', 'source_fingerprint' => $fingerprint, 'created_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $computed = 0;
            $blocked = 0;
            foreach ($groups as $group) {
                $result = $this->calculate($group['items']);
                $run->entries()->create(['school_id' => TenantContext::require()->schoolId, 'academic_session_id' => $group['session_id'], 'academic_term_id' => $academicTerm->getKey(), 'academic_subject_offering_id' => $group['offering_id'], 'student_id' => $group['student_id'], ...$result]);
                $result['status'] === 'computed' ? $computed++ : $blocked++;
            }
            $run->update(['status' => $blocked > 0 ? 'blocked' : 'completed', 'computed_count' => $computed, 'not_computable_count' => $blocked]);

            return $this->showRun($run->fresh());
        });
    }

    /** @param list<array{instance: mixed, entries: mixed}> $items
     * @return array<string, mixed>
     */
    private function calculate(array $items): array
    {
        $score = BigDecimal::zero();
        $maximum = BigDecimal::zero();
        foreach ($items as $item) {
            $instance = $item['instance'];
            $hasSpecial = false;
            foreach ($item['entries'] as $entry) {
                if (in_array($entry->status, ['absent', 'exempt'], true)) {
                    $hasSpecial = true;
                    if ($instance->policy->score_state_rule === 'block') {
                        return ['status' => 'not_computable', 'error_code' => 'SPECIAL_SCORE_STATE'];
                    }
                }
                if ($entry->status === 'entered') {
                    $score = $score->plus((string) $entry->score);
                    $maximum = $maximum->plus((string) $entry->component->max_marks);
                } elseif ($entry->status === 'absent') {
                    $maximum = $maximum->plus((string) $entry->component->max_marks);
                } elseif ($entry->status === 'exempt' && $instance->policy->score_state_rule !== 'absent_zero_exempt_excluded') {
                    $maximum = $maximum->plus((string) $entry->component->max_marks);
                }
            }
            if ($hasSpecial && $instance->policy->score_state_rule === 'block') {
                return ['status' => 'not_computable', 'error_code' => 'SPECIAL_SCORE_STATE'];
            }
        }
        if ($maximum->isZero()) {
            return ['status' => 'not_computable', 'error_code' => 'NO_MARKS'];
        }
        $percentage = $score->dividedBy($maximum, 4, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp);
        $last = $items[array_key_last($items)]['instance'];
        $band = $last->gradingScale->bands->first(fn ($band): bool => BigDecimal::of((string) $band->minimum_percentage)->compareTo($percentage) <= 0 && BigDecimal::of((string) $band->maximum_percentage)->compareTo($percentage) >= 0);
        if ($band === null) {
            return ['status' => 'not_computable', 'error_code' => 'NO_GRADING_BAND'];
        }

        return ['status' => 'computed', 'total_score' => $score->toScale(2, RoundingMode::HalfUp)->toString(), 'total_max_marks' => $maximum->toScale(2, RoundingMode::HalfUp)->toString(), 'percentage' => $percentage->toString(), 'grade' => $band->grade, 'grade_label' => $band->label, 'is_passing' => (bool) $band->is_passing, 'remark' => $band->remark, 'error_code' => null];
    }

    /** @return array{0: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        if (! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm];
    }

    private function assertLeadership(UserIdentity $actor): void
    {
        $context = TenantContext::require();
        if (! SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer'])))->exists()) {
            throw new ModelNotFoundException;
        }
    }

    /** @return Builder<AcademicResultEntry> */
    private function visibleEntries(UserIdentity $actor, AcademicResultRun $run, AcademicTerm $term): Builder
    {
        $query = AcademicResultEntry::query()->where('academic_result_run_id', $run->getKey())->with(['student', 'offering']);
        $this->assertLeadershipOrAssigned($actor, $query, $term);

        return $query;
    }

    /** @param Builder<AcademicResultEntry> $query */
    private function assertLeadershipOrAssigned(UserIdentity $actor, Builder $query, AcademicTerm $term): void
    {
        $context = TenantContext::require();
        $leadership = SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($builder) => $builder->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer'])))->exists();
        if (! $leadership) {
            $query->whereHas('offering.teachingAssignments', fn ($builder) => $builder->where('teacher_id', $actor->getKey())->where('academic_term_id', $term->getKey())->where('status', 'active'));
        }
    }

    /** @return array<string, mixed> */
    private function showRun(AcademicResultRun $run): array
    {
        return ['run' => $this->runData($run), 'entries' => $run->entries()->with(['student', 'offering'])->get()->map(fn (AcademicResultEntry $entry): array => $this->entryData($entry))->values()->all()];
    }

    /** @return array<string, mixed> */
    private function runData(AcademicResultRun $run): array
    {
        return ['id' => (string) $run->public_id, 'status' => (string) $run->status, 'computed_count' => (int) $run->computed_count, 'not_computable_count' => (int) $run->not_computable_count, 'created_at' => $run->created_at?->toISOString()];
    }

    /** @return array<string, mixed> */
    private function entryData(AcademicResultEntry $entry): array
    {
        return ['id' => (string) $entry->public_id, 'student_id' => (string) $entry->student?->public_id, 'student_name' => (string) $entry->student?->display_name, 'offering_id' => (string) $entry->offering?->public_id, 'status' => (string) $entry->status, 'total_score' => $entry->total_score === null ? null : (float) $entry->total_score, 'total_max_marks' => $entry->total_max_marks === null ? null : (float) $entry->total_max_marks, 'percentage' => $entry->percentage === null ? null : (float) $entry->percentage, 'grade' => $entry->grade, 'grade_label' => $entry->grade_label, 'is_passing' => $entry->is_passing, 'remark' => $entry->remark, 'error_code' => $entry->error_code];
    }
}
