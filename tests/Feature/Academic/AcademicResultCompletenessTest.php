<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicResultCompletenessTest extends AcademicScoreSubmissionTest
{
    public function test_incomplete_result_inputs_are_reported_without_mutation(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'completeness-admin@example.com', 'completeness-password');
        $token = $this->loginAndSelect($admin, $school, 'completeness-password');
        $configuration = $this->configuration($school, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-completeness';

        $response = $this->withToken($token)->getJson($path)->assertOk();
        $response->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.checks.0.key', 'active_academic_period')
            ->assertJsonPath('data.checks.1.status', 'ready')
            ->assertJsonPath('data.checks.4.error_code', 'APPROVED_SUBMISSION_MISSING')
            ->assertJsonMissing(['score' => 18])
            ->assertJsonMissingPath('data.internal_id');
    }

    public function test_complete_approved_roster_is_ready_for_result_computation(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'completeness-ready@example.com', 'completeness-ready-password');
        $token = $this->loginAndSelect($admin, $school, 'completeness-ready-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'COMPLETE-001', 'display_name' => 'Fictional Completeness Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 18, 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'entered', 'score' => 72, 'expected_version' => 0],
        ]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/approve')->assertOk();

        $path = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-completeness';
        $response = $this->withToken($token)->getJson($path)->assertOk();
        $response->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks.4.status', 'ready')
            ->assertJsonPath('data.checks.5.status', 'ready')
            ->assertJsonPath('data.assessment_instances.0.missing_score_entries', 0);
    }
}
