<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicSubject;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\ImportCommitAction;
use App\Contexts\Platform\Domain\Models\HistoricalAcademicImport;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportDryRunReport;
use App\Contexts\Platform\Domain\Models\ImportDuplicateFinding;
use App\Contexts\Platform\Domain\Models\ImportMapping;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HistoricalAcademicImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_historical_rows_are_archived_as_unverified_and_replay_is_idempotent(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken();
        $references = TenantContext::runInternal(TenantContext::fromMembership($membership), 'historical import test setup', function () use ($school, $identity): array {
            $session = AcademicSession::factory()->create(['school_id' => $school->id, 'status' => 'active']);
            $term = AcademicTerm::factory()->create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'status' => 'active']);
            $level = AcademicLevel::factory()->create(['school_id' => $school->id]);
            $section = AcademicSection::factory()->create(['school_id' => $school->id, 'academic_level_id' => $level->id]);
            $classArm = AcademicClassArm::query()->create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'name' => 'Historical Arm', 'code' => 'HIST', 'capacity' => 30, 'status' => 'active']);
            $subject = AcademicSubject::factory()->create(['school_id' => $school->id]);
            $offering = AcademicSubjectOffering::factory()->create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'academic_class_arm_id' => $classArm->id, 'academic_subject_id' => $subject->id]);
            $student = Student::query()->create(['school_id' => $school->id, 'student_number' => 'HIST-001', 'display_name' => 'Fictional Historical Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
            $batch = ImportBatch::query()->create(['school_id' => $school->id, 'template_key' => 'historical_academic_results', 'template_version' => 'v1', 'original_filename' => 'historical.csv', 'format' => 'csv', 'size' => 100, 'checksum' => hash('sha256', 'historical'), 'uploaded_by' => $identity->id, 'status' => 'staged', 'row_count' => 1]);
            $values = ['student_number' => 'HIST-001', 'academic_session' => $session->public_id, 'academic_term' => $term->public_id, 'subject_offering' => $offering->public_id, 'score' => '75', 'max_marks' => '100', 'provenance_reference' => 'archive-row-1'];
            $row = ImportStagedRow::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'row_number' => 1, 'cells' => $values, 'normalized_cells' => $values, 'status' => 'valid']);
            $mapping = ImportMapping::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'historical_academic_results', 'template_version' => 'v1', 'mapping_version' => 1, 'mappings' => array_map(static fn (string $field): array => ['source' => $field, 'target' => $field], array_keys($values)), 'status' => 'complete', 'created_by' => $identity->id]);
            $hashes = app(ImportCommitAction::class);
            ImportDryRunReport::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'historical_academic_results', 'template_version' => 'v1', 'mapping_version' => $mapping->mapping_version, 'validation_snapshot_hash' => $hashes->validationHash(ImportStagedRow::query()->whereKey($row->id)->get()), 'duplicate_snapshot_hash' => $hashes->duplicateHash(ImportDuplicateFinding::query()->where('import_batch_id', $batch->id)->get()), 'status' => 'ready', 'report' => ['ready_for_commit' => true], 'generated_by' => $identity->id]);

            return [$batch->public_id, $session->public_id, $term->public_id, $offering->public_id];
        });

        $path = '/api/v1/schools/'.$school->public_id.'/imports/'.$references[0].'/historical-academic';
        $first = $this->withToken($token)->postJson($path)->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.counts.accepted', 1);
        $this->withToken($token)->postJson($path)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        self::assertDatabaseCount('historical_academic_imports', 1);
        self::assertDatabaseCount('historical_academic_records', 1);
        self::assertDatabaseCount('students', 1);
        self::assertSame('unverified', (string) HistoricalAcademicImport::query()->firstOrFail()->records()->firstOrFail()->status);
    }

    public function test_unavailable_references_are_rejected_without_mutating_students(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken();
        $batch = TenantContext::runInternal(TenantContext::fromMembership($membership), 'historical import rejection test setup', function () use ($school, $identity): string {
            $batch = ImportBatch::query()->create(['school_id' => $school->id, 'template_key' => 'historical_academic_results', 'template_version' => 'v1', 'original_filename' => 'historical.csv', 'format' => 'csv', 'size' => 100, 'checksum' => hash('sha256', 'missing'), 'uploaded_by' => $identity->id, 'status' => 'staged', 'row_count' => 1]);
            $values = ['student_number' => 'UNKNOWN', 'academic_session' => '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1001', 'academic_term' => '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1002', 'subject_offering' => '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1003', 'score' => '75', 'max_marks' => '100'];
            $row = ImportStagedRow::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'row_number' => 1, 'cells' => $values, 'normalized_cells' => $values, 'status' => 'valid']);
            $mapping = ImportMapping::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'historical_academic_results', 'template_version' => 'v1', 'mapping_version' => 1, 'mappings' => array_map(static fn (string $field): array => ['source' => $field, 'target' => $field], array_keys($values)), 'status' => 'complete', 'created_by' => $identity->id]);
            $hashes = app(ImportCommitAction::class);
            ImportDryRunReport::query()->create(['import_batch_id' => $batch->id, 'school_id' => $school->id, 'template_key' => 'historical_academic_results', 'template_version' => 'v1', 'mapping_version' => $mapping->mapping_version, 'validation_snapshot_hash' => $hashes->validationHash(ImportStagedRow::query()->whereKey($row->id)->get()), 'duplicate_snapshot_hash' => $hashes->duplicateHash(ImportDuplicateFinding::query()->where('import_batch_id', $batch->id)->get()), 'status' => 'ready', 'report' => ['ready_for_commit' => true], 'generated_by' => $identity->id]);

            return (string) $batch->public_id;
        });

        $response = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/historical-academic')->assertOk();
        $response->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rows.0.error_code', 'reference_unavailable');
        self::assertDatabaseCount('students', 0);
    }

    /** @return array{0: School, 1: string, 2: SchoolMembership, 3: UserIdentity} */
    private function schoolToken(): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => 'historical-'.uniqid().'@example.test', 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token, $membership, $identity];
    }
}
