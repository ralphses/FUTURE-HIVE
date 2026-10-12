<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyVersion;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreEntry;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicGradingScaleVersion;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class AcademicResultCompletenessAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return array{ready: bool, status: string, checks: list<array{key: string, status: string, error_code: ?string, assessment_instance_count: ?int, approved_count: ?int, missing_score_entries: ?int}>, assessment_instances: list<array{assessment_instance_id: string, offering_id: string, status: string, approved_submission: bool, roster_count: int, component_count: int, expected_score_entries: int, recorded_score_entries: int, missing_score_entries: int}>} */
    public function execute(UserIdentity $actor, string $school, string $session, string $term): array
    {
        $academicTerm = $this->authorizeTerm($actor, $school, $session, $term);

        return $this->report($academicTerm);
    }

    public function assertForComputation(AcademicTerm $academicTerm): void
    {
        $report = $this->report($academicTerm);
        $blockingKeys = ['active_academic_period', 'subject_offerings', 'assessment_policies', 'grading_scales'];

        foreach ($report['checks'] as $check) {
            if (in_array($check['key'], $blockingKeys, true) && $check['status'] !== 'ready') {
                throw ValidationException::withMessages(['result_run' => ['Academic result configuration is incomplete.']]);
            }
        }
    }

    /** @return array{ready: bool, status: string, checks: list<array{key: string, status: string, error_code: ?string, assessment_instance_count: ?int, approved_count: ?int, missing_score_entries: ?int}>, assessment_instances: list<array{assessment_instance_id: string, offering_id: string, status: string, approved_submission: bool, roster_count: int, component_count: int, expected_score_entries: int, recorded_score_entries: int, missing_score_entries: int}>} */
    private function report(AcademicTerm $academicTerm): array
    {
        $session = $academicTerm->session;
        $checks = [];
        $checks[] = [
            'key' => 'active_academic_period',
            'status' => $academicTerm->status === 'active' && $session?->status === 'active' ? 'ready' : 'invalid',
            'error_code' => $academicTerm->status === 'active' && $session?->status === 'active' ? null : 'ACADEMIC_PERIOD_UNAVAILABLE',
            'assessment_instance_count' => null,
            'approved_count' => null,
            'missing_score_entries' => null,
        ];

        $offerings = AcademicSubjectOffering::query()->where('academic_term_id', $academicTerm->getKey())->where('status', 'active')->with(['session', 'level', 'section', 'classArm', 'subject'])->get();
        $offeringStatus = $offerings->isEmpty() ? 'missing' : 'ready';
        foreach ($offerings as $offering) {
            if ($offering->academic_session_id !== $academicTerm->academic_session_id || $offering->session?->status !== 'active' || $offering->level?->status !== 'active' || $offering->section?->status !== 'active' || $offering->classArm?->status !== 'active' || $offering->subject?->status !== 'active') {
                $offeringStatus = 'invalid';
                break;
            }
        }
        $checks[] = ['key' => 'subject_offerings', 'status' => $offeringStatus, 'error_code' => $offeringStatus === 'ready' ? null : ($offeringStatus === 'missing' ? 'NO_ACTIVE_OFFERINGS' : 'INVALID_OFFERING'), 'assessment_instance_count' => null, 'approved_count' => null, 'missing_score_entries' => null];

        $policies = AcademicAssessmentPolicyVersion::query()->where('academic_term_id', $academicTerm->getKey())->where('status', 'active')->with('components')->get();
        $scales = AcademicGradingScaleVersion::query()->where('status', 'active')->with('bands')->get();
        $policyStatus = $offerings->isEmpty() ? 'missing' : 'ready';
        $scaleStatus = $policies->isEmpty() ? 'missing' : 'ready';
        foreach ($offerings as $offering) {
            $policy = $policies->first(fn ($item): bool => $item->academic_subject_offering_id === $offering->getKey());
            if ($policy === null || $policy->components->isEmpty()) {
                $policyStatus = 'missing';

                continue;
            }
            if ((string) $policy->total_marks !== (string) $policy->components->sum('max_marks')) {
                $policyStatus = 'invalid';
            }
            $scale = $scales->first(fn ($item): bool => $item->academic_assessment_policy_version_id === $policy->getKey());
            if ($scale === null || $scale->bands->isEmpty()) {
                $scaleStatus = 'missing';
            } elseif ($policy->status !== 'active') {
                $scaleStatus = 'invalid';
            }
        }
        $checks[] = ['key' => 'assessment_policies', 'status' => $policyStatus, 'error_code' => $policyStatus === 'ready' ? null : ($policyStatus === 'missing' ? 'ASSESSMENT_POLICY_MISSING' : 'ASSESSMENT_POLICY_INVALID'), 'assessment_instance_count' => null, 'approved_count' => null, 'missing_score_entries' => null];
        $checks[] = ['key' => 'grading_scales', 'status' => $scaleStatus, 'error_code' => $scaleStatus === 'ready' ? null : ($scaleStatus === 'missing' ? 'GRADING_SCALE_MISSING' : 'GRADING_SCALE_INVALID'), 'assessment_instance_count' => null, 'approved_count' => null, 'missing_score_entries' => null];

        $instances = AcademicAssessmentInstance::query()->where('academic_term_id', $academicTerm->getKey())->where('status', '<>', 'cancelled')->with(['policy.components', 'gradingScale.bands', 'offering'])->get();
        $details = [];
        $approvedCount = 0;
        $missingCount = 0;
        foreach ($instances as $instance) {
            $policy = $instance->policy;
            $componentIds = $policy?->components->pluck('id')->all() ?? [];
            $date = CarbonImmutable::parse($instance->assessment_date);
            $studentIds = StudentEnrollment::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_class_arm_id', $instance->offering?->academic_class_arm_id)->where('status', 'active')->whereDate('start_date', '<=', $date->toDateString())->where(function ($query) use ($date): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString());
            })->pluck('student_id')->all();
            $expected = count($studentIds) * count($componentIds);
            $actual = $expected === 0 || $componentIds === [] ? 0 : AcademicAssessmentScoreEntry::query()->where('academic_assessment_instance_id', $instance->getKey())->whereIn('student_id', $studentIds)->whereIn('academic_assessment_policy_component_id', $componentIds)->count();
            $missing = max(0, $expected - $actual);
            $approved = AcademicAssessmentSubmission::query()->where('academic_assessment_instance_id', $instance->getKey())->where('status', 'approved')->exists();
            $approvedCount += $approved ? 1 : 0;
            $missingCount += $missing;
            $details[] = ['assessment_instance_id' => (string) $instance->public_id, 'offering_id' => (string) $instance->offering?->public_id, 'status' => $approved && $missing === 0 ? 'ready' : 'missing', 'approved_submission' => $approved, 'roster_count' => count($studentIds), 'component_count' => count($componentIds), 'expected_score_entries' => $expected, 'recorded_score_entries' => $actual, 'missing_score_entries' => $missing];
        }
        $coverageStatus = $instances->isEmpty() ? 'missing' : ($missingCount > 0 ? 'missing' : 'ready');
        $checks[] = ['key' => 'approved_submissions', 'status' => $instances->isEmpty() || $approvedCount !== $instances->count() ? 'missing' : 'ready', 'error_code' => $instances->isEmpty() || $approvedCount !== $instances->count() ? 'APPROVED_SUBMISSION_MISSING' : null, 'assessment_instance_count' => $instances->count(), 'approved_count' => $approvedCount, 'missing_score_entries' => null];
        $checks[] = ['key' => 'score_coverage', 'status' => $coverageStatus, 'error_code' => $coverageStatus === 'ready' ? null : 'SCORE_COVERAGE_INCOMPLETE', 'assessment_instance_count' => null, 'approved_count' => null, 'missing_score_entries' => $missingCount];

        $structuralReady = (bool) collect($checks)->whereIn('key', ['active_academic_period', 'subject_offerings', 'assessment_policies', 'grading_scales'])->every(fn (array $check): bool => $check['status'] === 'ready');
        $ready = (bool) ($structuralReady && collect($checks)->whereIn('key', ['approved_submissions', 'score_coverage'])->every(fn (array $check): bool => $check['status'] === 'ready'));

        return ['ready' => $ready, 'status' => $ready ? 'ready' : 'blocked', 'checks' => $checks, 'assessment_instances' => $details];
    }

    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term): AcademicTerm
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'academic.results.completeness.read')) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->with('session')->first();
        if (! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return $academicTerm;
    }
}
