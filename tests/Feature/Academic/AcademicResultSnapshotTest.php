<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicResultEntry;
use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;

final class AcademicResultSnapshotTest extends AcademicScoreSubmissionTest
{
    public function test_published_complete_result_run_creates_one_immutable_snapshot_idempotently(): void
    {
        [$school, $publisher] = $this->schoolWithRole('proprietor', 'snapshot-publisher@example.com', 'snapshot-password');
        $token = $this->loginAndSelect($publisher, $school, 'snapshot-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'SNAP-001', 'display_name' => 'Fictional Snapshot Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $instance = AcademicAssessmentInstance::query()->where('public_id', $configuration['instance'])->firstOrFail();
        AcademicAssessmentSubmission::create(['school_id' => $school->id, 'academic_assessment_instance_id' => $instance->id, 'academic_term_id' => $configuration['term_id'], 'academic_subject_offering_id' => $configuration['offering'] ? AcademicSubjectOffering::query()->where('public_id', $configuration['offering'])->value('id') : null, 'submitted_by' => $publisher->id, 'status' => 'approved', 'entry_count' => 1, 'submitted_at' => now()]);
        $run = AcademicResultRun::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'completed', 'source_fingerprint' => hash('sha256', 'fictional-snapshot-source'), 'computed_count' => 1, 'not_computable_count' => 0, 'created_by' => $publisher->id]);
        $offeringId = AcademicSubjectOffering::query()->where('public_id', $configuration['offering'])->value('id');
        $entry = AcademicResultEntry::create(['school_id' => $school->id, 'academic_result_run_id' => $run->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_subject_offering_id' => $offeringId, 'student_id' => $student->id, 'status' => 'computed', 'total_score' => 80, 'total_max_marks' => 100, 'percentage' => 80, 'grade' => 'P', 'grade_label' => 'Pass', 'is_passing' => true, 'remark' => 'Fictional result', 'error_code' => null]);
        AcademicResultGate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $run->id, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs/'.$run->public_id;

        $first = $this->withToken($token)->postJson($base.'/snapshot')->assertCreated()->assertJsonPath('data.snapshot.status', 'published')->assertJsonPath('data.snapshot.entry_count', 1)->assertJsonPath('data.entries.0.source_result_entry_id', (string) $entry->public_id);
        $second = $this->withToken($token)->postJson($base.'/snapshot')->assertCreated();

        self::assertSame($first->json('data.snapshot.id'), $second->json('data.snapshot.id'));
        self::assertDatabaseCount('academic_result_snapshots', 1);
        self::assertDatabaseCount('academic_result_snapshot_entries', 1);
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-snapshots')->assertOk()->assertJsonPath('data.0.status', 'published');
    }

    public function test_blocked_result_run_cannot_create_a_snapshot(): void
    {
        [$school, $publisher] = $this->schoolWithRole('proprietor', 'blocked-snapshot@example.com', 'blocked-snapshot-password');
        $token = $this->loginAndSelect($publisher, $school, 'blocked-snapshot-password');
        $configuration = $this->configuration($school, $token);
        $run = AcademicResultRun::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'blocked', 'source_fingerprint' => hash('sha256', 'fictional-blocked-snapshot'), 'computed_count' => 0, 'not_computable_count' => 1, 'created_by' => $publisher->id]);
        AcademicResultGate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $run->id, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-runs/'.$run->public_id.'/snapshot')->assertUnprocessable();
        self::assertDatabaseCount('academic_result_snapshots', 0);
    }
}
