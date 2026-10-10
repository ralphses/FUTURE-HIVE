<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyVersion;
use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicGradingScaleVersion;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AcademicReadinessAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return array{ready: bool, checks: list<array{key: string, status: string}>} */
    public function execute(UserIdentity $actor, string $school): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'academic.readiness.read')) {
            throw new ModelNotFoundException;
        }

        $checks = [];
        $schoolModel = School::query()->whereKey($context->schoolId)->first();
        $checks[] = ['key' => 'school_lifecycle', 'status' => $schoolModel?->status === 'active' ? 'ready' : 'invalid'];

        $sessions = AcademicSession::query()->where('status', 'active')->get();
        $session = $sessions->count() === 1 ? $sessions->first() : null;
        $checks[] = ['key' => 'active_academic_period', 'status' => $sessions->count() === 0 ? 'missing' : ($session === null ? 'invalid' : $this->activeTermStatus($session))];

        $classArms = AcademicClassArm::query()->where('status', 'active')->with(['level', 'section'])->get();
        $structureStatus = $classArms->isEmpty() ? 'missing' : 'ready';
        foreach ($classArms as $classArm) {
            if ($classArm->level?->status !== 'active' || $classArm->section?->status !== 'active' || $classArm->section?->academic_level_id !== $classArm->academic_level_id) {
                $structureStatus = 'invalid';
                break;
            }
        }
        $checks[] = ['key' => 'academic_structure', 'status' => $structureStatus];

        $offerings = AcademicSubjectOffering::query()->where('status', 'active')->with(['session', 'term', 'level', 'section', 'classArm', 'subject'])->get();
        $offeringStatus = $offerings->isEmpty() ? 'missing' : 'ready';
        foreach ($offerings as $offering) {
            if ($offering->session?->status !== 'active' || $offering->term?->status !== 'active' || $offering->term?->academic_session_id !== $offering->academic_session_id || $offering->level?->status !== 'active' || $offering->section?->status !== 'active' || $offering->classArm?->status !== 'active' || $offering->subject?->status !== 'active') {
                $offeringStatus = 'invalid';
                break;
            }
        }
        $checks[] = ['key' => 'subject_offerings', 'status' => $offeringStatus];

        $policies = AcademicAssessmentPolicyVersion::query()->where('status', 'active')->with('components')->get();
        $policyStatus = $offerings->isEmpty() ? 'missing' : 'ready';
        $activePolicyOfferingIds = $policies->pluck('academic_subject_offering_id')->all();
        foreach ($offerings as $offering) {
            $matching = $policies->where('academic_subject_offering_id', $offering->getKey())->where('academic_term_id', $offering->academic_term_id);
            if ($matching->isEmpty()) {
                $policyStatus = 'missing';
                break;
            }
            foreach ($matching as $policy) {
                if ($policy->components->isEmpty() || $policy->total_marks !== $policy->components->sum('max_marks')) {
                    $policyStatus = 'invalid';
                    break 2;
                }
            }
        }
        $checks[] = ['key' => 'assessment_policies', 'status' => $policyStatus];

        $scales = AcademicGradingScaleVersion::query()->where('status', 'active')->with(['bands', 'policyVersion'])->get();
        $scaleStatus = $policies->isEmpty() ? 'missing' : 'ready';
        foreach ($policies as $policy) {
            $matching = $scales->where('academic_assessment_policy_version_id', $policy->getKey());
            if ($matching->isEmpty()) {
                $scaleStatus = 'missing';
                break;
            }
            foreach ($matching as $scale) {
                if ($scale->bands->isEmpty() || $scale->policyVersion?->status !== 'active') {
                    $scaleStatus = 'invalid';
                    break 2;
                }
            }
        }
        $checks[] = ['key' => 'grading_scales', 'status' => $scaleStatus];

        return ['ready' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'ready'), 'checks' => $checks];
    }

    private function activeTermStatus(AcademicSession $session): string
    {
        $terms = AcademicTerm::query()->where('academic_session_id', $session->getKey())->where('status', 'active')->get();

        return $terms->count() === 1 ? 'ready' : ($terms->isEmpty() ? 'missing' : 'invalid');
    }
}
