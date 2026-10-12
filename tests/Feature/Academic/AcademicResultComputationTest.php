<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicResultComputationTest extends AcademicScoreSubmissionTest
{
    public function test_approved_scores_are_computed_idempotently_from_immutable_sources(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'results-admin@example.com', 'results-password');
        $token = $this->loginAndSelect($admin, $school, 'results-password');
        $configuration = $this->configuration($school, $token);
        $student = $this->admitStudent($school, $configuration, 'RESULT-001', 'Fictional Results Learner');
        $base = $this->path($school, $configuration);

        $this->withToken($token)->putJson($base.'/scores', ['entries' => [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 18, 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'entered', 'score' => 72, 'expected_version' => 0],
        ]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/approve')->assertOk();

        $runPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs';
        $first = $this->withToken($token)->postJson($runPath)->assertOk();
        $first->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.run.computed_count', 1)
            ->assertJsonPath('data.entries.0.percentage', 90)
            ->assertJsonPath('data.entries.0.grade', 'P');

        $second = $this->withToken($token)->postJson($runPath)->assertOk();
        self::assertSame($first->json('data.run.id'), $second->json('data.run.id'));
        $this->withToken($token)->getJson($runPath)->assertOk()->assertJsonCount(1, 'data.items');
        $this->withToken($token)->getJson($runPath.'/'.$first->json('data.run.id'))->assertOk()->assertJsonPath('data.entries.0.student_id', $student->public_id);
        $this->withToken($token)->getJson($runPath.'/'.$first->json('data.run.id').'/students/'.$student->public_id)->assertOk();
    }

    public function test_special_score_states_block_computation_without_mutating_scores(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'results-special@example.com', 'results-special-password');
        $token = $this->loginAndSelect($admin, $school, 'results-special-password');
        $configuration = $this->configuration($school, $token);
        $student = $this->admitStudent($school, $configuration, 'RESULT-002', 'Fictional Special-State Learner');
        $base = $this->path($school, $configuration);

        $this->withToken($token)->putJson($base.'/scores', ['entries' => [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 20, 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'absent', 'expected_version' => 0],
        ]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/approve')->assertOk();

        $runPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs';
        $this->withToken($token)->postJson($runPath)->assertOk()
            ->assertJsonPath('data.run.status', 'blocked')
            ->assertJsonPath('data.run.not_computable_count', 1)
            ->assertJsonPath('data.entries.0.status', 'not_computable')
            ->assertJsonPath('data.entries.0.error_code', 'SPECIAL_SCORE_STATE');
    }

    public function test_explicit_policy_rule_treats_absent_as_zero_and_excludes_exempt_marks(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'results-policy@example.com', 'results-policy-password');
        $token = $this->loginAndSelect($admin, $school, 'results-policy-password');
        $configuration = $this->configuration($school, $token, 'absent_zero_exempt_excluded');
        $student = $this->admitStudent($school, $configuration, 'RESULT-003', 'Fictional Policy Learner');
        $base = $this->path($school, $configuration);

        $this->withToken($token)->putJson($base.'/scores', ['entries' => [
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'absent', 'expected_version' => 0],
            ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][1], 'status' => 'exempt', 'expected_version' => 0],
        ]])->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submit')->assertOk();
        $this->withToken($token)->postJson($base.'/scores/submission/approve')->assertOk();

        $runPath = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs';
        $this->withToken($token)->postJson($runPath)->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.entries.0.total_score', 0)
            ->assertJsonPath('data.entries.0.total_max_marks', 20)
            ->assertJsonPath('data.entries.0.percentage', 0);
    }

    /** @param array<string, mixed> $configuration */
    private function admitStudent(School $school, array $configuration, string $number, string $name): Student
    {
        $student = Student::create(['school_id' => $school->id, 'student_number' => $number, 'display_name' => $name, 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);

        return $student;
    }
}
