<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleCriterion;
use App\Contexts\Academic\Domain\Models\AcademicPromotionRuleSet;
use App\Contexts\Academic\Domain\Models\AcademicResultEntry;
use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicPromotionEligibilityTest extends AcademicScoreSubmissionTest
{
    public function test_leadership_can_read_eligible_and_ineligible_summaries_without_mutation(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'eligibility-admin@example.com', 'eligibility-password');
        $token = $this->loginAndSelect($admin, $school, 'eligibility-password');
        $configuration = $this->configuration($school, $token);
        $target = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Next Fictional Level', 'code' => 'NEXT', 'sequence' => 2, 'status' => 'active']);
        $rule = AcademicPromotionRuleSet::create(['school_id' => $school->id, 'source_academic_level_id' => $configuration['level_id'], 'target_academic_level_id' => $target->id, 'name' => 'Fictional promotion rule', 'status' => 'active']);
        AcademicPromotionRuleCriterion::create(['school_id' => $school->id, 'academic_promotion_rule_set_id' => $rule->id, 'metric' => 'overall_percentage', 'operator' => 'gte', 'threshold' => 75, 'is_required' => true, 'sequence' => 1]);

        $eligible = $this->publishedStudent($school, $admin, $configuration, 'ELIGIBLE-001', 'Fictional Eligible Learner', 80);
        $ineligible = $this->publishedStudent($school, $admin, $configuration, 'INELIGIBLE-001', 'Fictional Ineligible Learner', 60);
        $before = AcademicResultSnapshot::query()->count();
        $path = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/promotion-eligibility';

        $response = $this->withToken($token)->getJson($path)->assertOk();
        $response->assertJsonPath('data.0.status', 'eligible')->assertJsonPath('data.1.status', 'ineligible');
        $this->withToken($token)->getJson($path.'/'.$eligible->public_id)->assertOk()->assertJsonPath('data.status', 'eligible')->assertJsonMissingPath('data.criteria.0.value');
        self::assertSame($before, AcademicResultSnapshot::query()->count());
        $this->withToken($token)->getJson($path.'/'.$ineligible->public_id)->assertOk()->assertJsonPath('data.status', 'ineligible');
    }

    public function test_missing_published_snapshot_is_not_computable_and_cross_school_is_generic(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'eligibility-principal@example.com', 'eligibility-principal-password');
        $token = $this->loginAndSelect($admin, $school, 'eligibility-principal-password');
        $configuration = $this->configuration($school, $token);
        $target = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Later Fictional Level', 'code' => 'LATER', 'sequence' => 2, 'status' => 'active']);
        $rule = AcademicPromotionRuleSet::create(['school_id' => $school->id, 'source_academic_level_id' => $configuration['level_id'], 'target_academic_level_id' => $target->id, 'name' => 'Fictional eligibility rule', 'status' => 'active']);
        AcademicPromotionRuleCriterion::create(['school_id' => $school->id, 'academic_promotion_rule_set_id' => $rule->id, 'metric' => 'overall_percentage', 'operator' => 'gte', 'threshold' => 50, 'is_required' => true, 'sequence' => 1]);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'NO-SNAPSHOT-001', 'display_name' => 'Fictional Unavailable Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);

        $path = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/promotion-eligibility/'.$student->public_id;
        $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.error_code', 'PUBLISHED_SNAPSHOT_UNAVAILABLE');
        $this->withToken($token)->getJson(str_replace((string) $school->public_id, '00000000-0000-7000-8000-000000000000', $path))->assertNotFound();
    }

    /** @param array<string, mixed> $configuration */
    private function publishedStudent(School $school, UserIdentity $publisher, array $configuration, string $number, string $name, float $percentage): Student
    {
        $student = Student::create(['school_id' => $school->id, 'student_number' => $number, 'display_name' => $name, 'admission_date' => '2025-09-01', 'status' => 'active']);
        $enrollment = StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $offeringId = AcademicSubjectOffering::query()->where('public_id', $configuration['offering'])->value('id');
        $run = AcademicResultRun::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'completed', 'source_fingerprint' => hash('sha256', $number), 'computed_count' => 1, 'not_computable_count' => 0, 'created_by' => $publisher->id]);
        $entry = AcademicResultEntry::create(['school_id' => $school->id, 'academic_result_run_id' => $run->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_subject_offering_id' => $offeringId, 'student_id' => $student->id, 'status' => 'computed', 'total_score' => $percentage, 'total_max_marks' => 100, 'percentage' => $percentage, 'grade' => 'P', 'grade_label' => 'Pass', 'is_passing' => $percentage >= 50, 'remark' => 'Fictional result']);
        $gate = AcademicResultGate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $run->id, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
        $snapshot = AcademicResultSnapshot::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_gate_id' => $gate->id, 'academic_result_run_id' => $run->id, 'source_fingerprint' => hash('sha256', 'snapshot-'.$number), 'snapshot_version' => 1, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
        AcademicResultSnapshotEntry::create(['school_id' => $school->id, 'academic_result_snapshot_id' => $snapshot->id, 'academic_result_entry_id' => $entry->id, 'student_id' => $student->id, 'student_enrollment_id' => $enrollment->id, 'academic_subject_offering_id' => $offeringId, 'status' => 'computed', 'total_score' => $percentage, 'total_max_marks' => 100, 'percentage' => $percentage, 'grade' => 'P', 'grade_label' => 'Pass', 'is_passing' => $percentage >= 50, 'remark' => 'Fictional result']);

        return $student;
    }
}
