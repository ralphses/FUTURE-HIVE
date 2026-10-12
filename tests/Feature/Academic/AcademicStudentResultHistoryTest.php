<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicResultEntry;
use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicStudentResultHistoryTest extends AcademicScoreSubmissionTest
{
    public function test_published_history_is_read_only_and_marks_the_terminal_version_current(): void
    {
        [$school, $publisher] = $this->schoolWithRole('school_admin', 'history-principal@example.com', 'history-password');
        $token = $this->loginAndSelect($publisher, $school, 'history-password');
        $configuration = $this->configuration($school, $token);
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $publisher->id)->firstOrFail();
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'principal')->value('id'), 'assigned_by' => $publisher->id, 'assigned_at' => now()]);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'HISTORY-001', 'display_name' => 'Fictional History Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $instance = AcademicAssessmentInstance::query()->where('public_id', $configuration['instance'])->firstOrFail();
        $offeringId = AcademicSubjectOffering::query()->where('public_id', $configuration['offering'])->value('id');
        AcademicAssessmentSubmission::create(['school_id' => $school->id, 'academic_assessment_instance_id' => $instance->id, 'academic_term_id' => $configuration['term_id'], 'academic_subject_offering_id' => $offeringId, 'submitted_by' => $publisher->id, 'status' => 'approved', 'entry_count' => 1, 'submitted_at' => now()]);
        $run = AcademicResultRun::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'completed', 'source_fingerprint' => hash('sha256', 'fictional-history-source'), 'computed_count' => 1, 'not_computable_count' => 0, 'created_by' => $publisher->id]);
        AcademicResultEntry::create(['school_id' => $school->id, 'academic_result_run_id' => $run->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_subject_offering_id' => $offeringId, 'student_id' => $student->id, 'status' => 'computed', 'total_score' => 82, 'total_max_marks' => 100, 'percentage' => 82, 'grade' => 'P', 'grade_label' => 'Pass', 'is_passing' => true, 'remark' => 'Fictional result', 'error_code' => null]);
        AcademicResultGate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $run->id, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'];
        $this->withToken($token)->postJson($base.'/result-runs/'.$run->public_id.'/snapshot')->assertCreated();

        $response = $this->withToken($token)->getJson($base.'/result-history')->assertOk();
        $response->assertJsonPath('data.0.is_current', true)->assertJsonPath('data.0.status', 'published');
        self::assertStringNotContainsString('source_fingerprint', $response->getContent());
        self::assertStringNotContainsString('school_id', $response->getContent());
        $response->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson($base.'/students/'.$student->public_id.'/result-history')->assertOk()->assertJsonPath('data.0.entries.0.student_id', (string) $student->public_id);
        self::assertDatabaseCount('academic_result_snapshots', 1);
    }
}
