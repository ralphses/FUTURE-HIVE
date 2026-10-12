<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicResultEntry;
use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Files\CloudinaryAssetClient;
use Illuminate\Http\UploadedFile;

final class AcademicResultExportTest extends AcademicScoreSubmissionTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CloudinaryAssetClient::class, new FakeResultExportCloudinaryClient);
    }

    public function test_authorized_result_export_returns_a_signed_private_download_link_without_mutating_snapshots(): void
    {
        [$school, $publisher] = $this->schoolWithRole('school_admin', 'result-export-admin@example.com', 'result-export-password');
        $token = $this->loginAndSelect($publisher, $school, 'result-export-password');
        $configuration = $this->configuration($school, $token);
        $student = Student::create(['school_id' => $school->id, 'student_number' => 'EXPORT-001', 'display_name' => 'Fictional Export Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
        StudentEnrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_level_id' => $configuration['level_id'], 'academic_section_id' => $configuration['section_id'], 'academic_class_arm_id' => $configuration['class_arm_id'], 'start_date' => '2025-09-01', 'status' => 'active']);
        $instance = AcademicAssessmentInstance::query()->where('public_id', $configuration['instance'])->firstOrFail();
        $offeringId = AcademicSubjectOffering::query()->where('public_id', $configuration['offering'])->value('id');
        AcademicAssessmentSubmission::create(['school_id' => $school->id, 'academic_assessment_instance_id' => $instance->id, 'academic_term_id' => $configuration['term_id'], 'academic_subject_offering_id' => $offeringId, 'submitted_by' => $publisher->id, 'status' => 'approved', 'entry_count' => 1, 'submitted_at' => now()]);
        $run = AcademicResultRun::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'status' => 'completed', 'source_fingerprint' => hash('sha256', 'fictional-export-source'), 'computed_count' => 1, 'not_computable_count' => 0, 'created_by' => $publisher->id]);
        AcademicResultEntry::create(['school_id' => $school->id, 'academic_result_run_id' => $run->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_subject_offering_id' => $offeringId, 'student_id' => $student->id, 'status' => 'computed', 'total_score' => 82, 'total_max_marks' => 100, 'percentage' => 82, 'grade' => 'P', 'grade_label' => 'Pass', 'is_passing' => true, 'remark' => 'Fictional result', 'error_code' => null]);
        AcademicResultGate::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_run_id' => $run->id, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
        $base = '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$configuration['session'].'/terms/'.$configuration['term'].'/result-exports';
        $snapshot = AcademicResultSnapshot::create(['school_id' => $school->id, 'academic_session_id' => $configuration['session_id'], 'academic_term_id' => $configuration['term_id'], 'academic_result_gate_id' => AcademicResultGate::query()->where('academic_result_run_id', $run->id)->value('id'), 'academic_result_run_id' => $run->id, 'source_fingerprint' => $run->source_fingerprint, 'status' => 'published', 'published_by' => $publisher->id, 'published_at' => now()]);
        AcademicResultSnapshotEntry::create(['school_id' => $school->id, 'academic_result_snapshot_id' => $snapshot->id, 'academic_result_entry_id' => AcademicResultEntry::query()->where('academic_result_run_id', $run->id)->value('id'), 'student_id' => $student->id, 'student_enrollment_id' => StudentEnrollment::query()->where('student_id', $student->id)->value('id'), 'academic_subject_offering_id' => $offeringId, 'status' => 'computed', 'total_score' => 82, 'total_max_marks' => 100, 'percentage' => 82, 'grade' => 'P', 'grade_label' => 'Pass', 'is_passing' => true, 'remark' => 'Fictional result']);

        $response = $this->withToken($token)->getJson($base.'?format=csv')->assertOk();

        $response->assertJsonPath('data.export.format', 'csv')->assertJsonPath('data.export.snapshot_version', 1);
        self::assertStringContainsString('/api/v1/files/download', (string) $response->json('data.export.download_url'));
        self::assertStringNotContainsString('cloudinary.test', $response->getContent());
        self::assertDatabaseCount('academic_result_snapshots', 1);
    }
}

final class FakeResultExportCloudinaryClient implements CloudinaryAssetClient
{
    public function upload(UploadedFile $file, array $options): array
    {
        return ['public_id' => $options['public_id'], 'format' => 'bin', 'resource_type' => 'raw'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function uploadPath(string $path, array $options): array
    {
        return ['public_id' => $options['public_id'], 'format' => $options['format'] ?? 'bin', 'resource_type' => 'raw'];
    }

    /** @param array<string, mixed> $options */
    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        return 'https://cloudinary.test/private/'.$format;
    }
}
