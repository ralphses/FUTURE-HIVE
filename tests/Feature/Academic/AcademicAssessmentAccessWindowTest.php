<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentAccessWindow;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicAssessmentAccessWindowTest extends AcademicScoreSubmissionTest
{
    public function test_access_window_is_readable_and_closing_is_idempotent(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'access-admin@example.com', 'access-password');
        $token = $this->loginAndSelect($admin, $school, 'access-password');
        $configuration = $this->configuration($school, $token);
        $base = $this->path($school, $configuration);

        $this->withToken($token)->getJson($base.'/access')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.currently_open', true);
        $this->withToken($token)->postJson($base.'/access/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->withToken($token)->postJson($base.'/access/close')->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('academic_assessment_access_windows', ['status' => 'closed']);
    }

    public function test_access_window_dates_must_be_ordered_and_term_contained(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'access-validation@example.com', 'access-validation-password');
        $token = $this->loginAndSelect($admin, $school, 'access-validation-password');
        $configuration = $this->configuration($school, $token);
        $base = $this->path($school, $configuration);
        AcademicAssessmentAccessWindow::query()->delete();

        $this->withToken($token)->putJson($base.'/access', ['opens_at' => '2025-12-01T00:00:00+00:00', 'closes_at' => '2025-11-30T23:59:59+00:00'])->assertUnprocessable();
        $this->withToken($token)->putJson($base.'/access', ['opens_at' => '2025-10-01T00:00:00+00:00', 'closes_at' => '2025-11-30T23:59:59+00:00'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->withToken($token)->postJson($base.'/access/activate')->assertOk()->assertJsonPath('data.status', 'active');

        self::assertCount(1, AcademicAssessmentAccessWindow::query()->get());
    }

    public function test_closed_access_window_blocks_score_changes(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'access-closed@example.com', 'access-closed-password');
        $token = $this->loginAndSelect($admin, $school, 'access-closed-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'ACCESS-001', 'display_name' => 'Fictional Access Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);

        $this->withToken($token)->postJson($base.'/access/close')->assertOk();
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 10, 'expected_version' => 0]]])->assertUnprocessable();
    }
}
