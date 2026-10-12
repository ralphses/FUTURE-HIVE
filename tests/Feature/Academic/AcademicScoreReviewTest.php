<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicScoreReviewTest extends AcademicScoreSubmissionTest
{
    public function test_leadership_can_return_resubmit_and_approve_scores(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'review-admin@example.com', 'review-password');
        $token = $this->loginAndSelect($admin, $school, 'review-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'REVIEW-001', 'display_name' => 'Fictional Review Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);
        $entries = [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 12, 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'exempt', 'expected_version' => 0],
        ];

        $this->withToken($token)->putJson($base.'/scores', ['entries' => $entries])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();
        $this->withToken($token)->getJson($base.'/scores/submission/review')->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->withToken($token)->postJson($base.'/scores/submission/return', ['reason' => 'Please verify the examination entry.'])->assertOk()->assertJsonPath('data.status', 'returned');
        $entries[0]['expected_version'] = 1;
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [$entries[0]]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/resubmit')->assertOk()->assertJsonPath('data.status', 'resubmitted');
        $this->withToken($token)->postJson($base.'/scores/submission/resubmit')->assertOk()->assertJsonPath('data.status', 'resubmitted');
        $this->withToken($token)->postJson($base.'/scores/submission/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [$entries[0]]])->assertUnprocessable();
    }

    public function test_return_requires_a_bounded_reason_and_approved_scores_cannot_be_returned(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'review-reason@example.com', 'review-reason-password');
        $token = $this->loginAndSelect($admin, $school, 'review-reason-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'REVIEW-002', 'display_name' => 'Fictional Review Reason Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 12, 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'absent', 'expected_version' => 0],
        ]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();

        $this->withToken($token)->postJson($base.'/scores/submission/return', ['reason' => 'x'])->assertUnprocessable();
        $this->withToken($token)->postJson($base.'/scores/submission/return', ['reason' => 'Please correct the score state.'])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/resubmit')->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/approve')->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/return', ['reason' => 'A later return is not allowed.'])->assertUnprocessable();
    }
}
