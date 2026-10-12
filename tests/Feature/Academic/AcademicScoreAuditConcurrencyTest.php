<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreAudit;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScoreEntry;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicScoreAuditConcurrencyTest extends AcademicScoreSubmissionTest
{
    public function test_score_revisions_reject_stale_writes_and_expose_safe_history(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'audit-admin@example.com', 'audit-password');
        $token = $this->loginAndSelect($admin, $school, 'audit-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'AUDIT-001', 'display_name' => 'Fictional Audit Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $base = $this->path($school, $configuration);
        $entry = ['student_id' => $student->public_id, 'component_id' => $configuration['component_ids'][0], 'status' => 'entered', 'score' => 10, 'expected_version' => 0];

        $this->withToken($token)->putJson($base.'/scores', ['entries' => [$entry]])->assertOk()->assertJsonPath('data.items.0.version', 1);
        $entry['score'] = 12;
        $entry['expected_version'] = 1;
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [$entry]])->assertOk()->assertJsonPath('data.items.0.version', 2);

        $stale = $entry;
        $stale['score'] = 14;
        $stale['expected_version'] = 1;
        $this->withToken($token)->putJson($base.'/scores', ['entries' => [$stale]])->assertUnprocessable();
        $this->assertDatabaseHas('academic_assessment_score_entries', ['student_id' => $student->id, 'score' => '12.00', 'revision' => 2]);
        self::assertSame(2, AcademicAssessmentScoreAudit::query()->count());

        $history = $this->withToken($token)->getJson($base.'/scores/audit-history')->assertOk();
        $history->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.revision_after', 2)->assertJsonMissingPath('data.0.school_id');
        $history->assertDontSee('AUDIT-001')->assertDontSee('audit-password');
        $entryHistory = $this->withToken($token)->getJson($base.'/scores/'.$student->public_id.'/'.$configuration['component_ids'][0].'/audit-history')->assertOk();
        $entryHistory->assertJsonPath('meta.total', 2);
        self::assertSame(1, AcademicAssessmentScoreEntry::query()->count());
    }
}
