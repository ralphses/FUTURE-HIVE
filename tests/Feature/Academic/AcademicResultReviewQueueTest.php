<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicResultReviewQueueTest extends AcademicScoreSubmissionTest
{
    public function test_leadership_can_view_safe_review_queue_with_blocked_issues(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'review-queue-admin@example.com', 'review-queue-password');
        $token = $this->loginAndSelect($admin, $school, 'review-queue-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'QUEUE-001', 'display_name' => 'Fictional Queue Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);

        $this->withToken($token)->putJson($base.'/scores', ['entries' => [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 10, 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'absent', 'expected_version' => 0],
        ]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/approve')->assertOk();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs')->assertOk();

        $queue = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-review-queue';
        $response = $this->withToken($token)->getJson($queue)->assertOk();
        $runId = $response->json('data.0.id');
        $response->assertJsonPath('data.0.completeness_status', 'blocked')
            ->assertJsonPath('data.0.review_status', 'blocked')
            ->assertJsonPath('data.0.issue_codes.0', 'SPECIAL_SCORE_STATE');

        $detail = $this->withToken($token)->getJson($queue.'/'.$runId)->assertOk();
        $detail->assertJsonPath('data.entries.0.error_code', 'SPECIAL_SCORE_STATE')
            ->assertJsonMissingPath('data.entries.0.total_score')
            ->assertJsonMissingPath('data.entries.0.internal_id');
    }

    public function test_teacher_without_review_permission_cannot_read_the_queue(): void
    {
        [$school, $teacher] = $this->schoolWithRole('teacher', 'review-queue-teacher@example.com', 'review-queue-teacher-password');
        $token = $this->loginAndSelect($teacher, $school, 'review-queue-teacher-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/unknown/terms/unknown/result-review-queue')->assertNotFound();
    }
}
