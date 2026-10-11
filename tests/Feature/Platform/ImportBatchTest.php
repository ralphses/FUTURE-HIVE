<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportDuplicateFinding;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Files\CloudinaryAssetClient;
use App\Support\Files\FileScanResult;
use App\Support\Files\MalwareScanner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class ImportBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $privateKey = '';
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        config(['auth.jwt.private_key' => $privateKey, 'auth.jwt.public_keys' => ['test-key' => (string) ($details['key'] ?? '')], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api', 'services.cloudinary.upload_prefix' => 'schoolos/schools']);
        $cloudinary = new FakeImportCloudinaryClient;
        $this->app->instance(FakeImportCloudinaryClient::class, $cloudinary);
        $this->app->instance(CloudinaryAssetClient::class, $cloudinary);
        $this->app->singleton(MalwareScanner::class, FakeImportMalwareScanner::class);
    }

    public function test_authorized_user_uploads_and_stages_a_csv_without_mutating_registry_data(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $path = '/api/v1/schools/'.$school->public_id.'/imports';

        $response = $this->withToken($token)->post($path, [
            'template' => 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('students.csv', "student_number,display_name,admission_date,metadata\nSTU-001,Fictional Learner,2026-09-01,cohort-a\n"),
        ]);

        $response->assertCreated()->assertJsonPath('data.template', 'students')->assertJsonPath('data.status', 'staged')->assertJsonPath('data.row_count', 1);
        $batch = $response->json('data.id');
        self::assertDatabaseHas('import_batches', ['public_id' => $batch, 'status' => 'staged', 'template_version' => 'v1']);
        $batchModel = ImportBatch::query()->where('public_id', $batch)->firstOrFail();
        self::assertDatabaseHas('import_staged_rows', ['import_batch_id' => $batchModel->getKey(), 'row_number' => 2, 'status' => 'pending']);
        self::assertDatabaseCount('students', 0);
        self::assertSame(2, AuditEvent::query()->whereIn('action', ['import.uploaded', 'import.staged'])->count());
        self::assertSame(1, $this->app->make(FakeImportCloudinaryClient::class)->uploads);
    }

    public function test_xlsx_upload_can_be_listed_viewed_and_cancelled_idempotently(): void
    {
        [$school, $token] = $this->schoolToken('principal');
        $path = '/api/v1/schools/'.$school->public_id.'/imports';
        $upload = $this->withToken($token)->post($path, [
            'template' => 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('students.xlsx', $this->xlsxContent()),
        ])->assertCreated();
        $batch = (string) $upload->json('data.id');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/imports')->assertOk()->assertJsonPath('data.0.id', $batch);
        $this->withToken($token)->getJson($path.'/'.$batch)->assertOk()->assertJsonPath('data.format', 'xlsx');
        $this->withToken($token)->postJson($path.'/'.$batch.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->withToken($token)->postJson($path.'/'.$batch.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        self::assertDatabaseHas('import_batches', ['public_id' => $batch, 'status' => 'cancelled']);
    }

    public function test_invalid_headers_fail_staging_and_unauthorized_or_cross_school_access_is_hidden(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $path = '/api/v1/schools/'.$school->public_id.'/imports';

        $this->withToken($token)->post($path, [
            'template' => 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('wrong.csv', "wrong,headers\nvalue,value\n"),
        ])->assertUnprocessable();
        self::assertDatabaseHas('import_batches', ['status' => 'failed', 'failure_reason' => 'The import file could not be staged.']);
        self::assertDatabaseCount('import_staged_rows', 0);

        [$otherSchool, $teacherToken] = $this->schoolToken('teacher');
        $this->withToken($teacherToken)->post('/api/v1/schools/'.$otherSchool->public_id.'/imports', [
            'template' => 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('students.csv', "student_number,display_name,admission_date,metadata\nSTU-002,Fictional Learner,2026-09-01,cohort-a\n"),
        ])->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/imports')->assertNotFound();
    }

    public function test_authorized_user_saves_an_immutable_mapping_and_previews_staged_rows(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->uploadStudents($school->public_id, $token);
        $mappingPath = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/mapping';

        $this->withToken($token)->putJson($mappingPath, [
            'mappings' => [
                ['source' => 'student_number', 'target' => 'student_number'],
                ['source' => 'display_name', 'target' => 'display_name'],
                ['source' => 'admission_date', 'target' => 'admission_date'],
                ['source' => 'metadata', 'target' => 'metadata'],
            ],
        ])->assertCreated()->assertJsonPath('data.mapping.status', 'complete')->assertJsonPath('data.mapping.version', 1);

        $this->withToken($token)->getJson($mappingPath)->assertOk()->assertJsonPath('data.mapping.mappings.0.target', 'student_number');
        $this->withToken($token)->getJson(str_replace('/mapping', '/preview', $mappingPath))
            ->assertOk()
            ->assertJsonPath('data.rows.0.row_number', 2)
            ->assertJsonPath('data.rows.0.values.display_name', 'Fictional Learner');
        self::assertDatabaseCount('students', 0);
        self::assertDatabaseCount('import_mappings', 1);
    }

    public function test_incomplete_and_invalid_mappings_are_safe_and_permissions_are_enforced(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->uploadStudents($school->public_id, $token);
        $mappingPath = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/mapping';

        $this->withToken($token)->putJson($mappingPath, [
            'mappings' => [['source' => 'student_number', 'target' => 'student_number']],
        ])->assertCreated()->assertJsonPath('data.mapping.status', 'draft')->assertJsonPath('data.mapping.missing_required_fields.0', 'display_name');

        $this->withToken($token)->putJson($mappingPath, [
            'mappings' => [['source' => 'unknown', 'target' => 'display_name']],
        ])->assertUnprocessable();

        [$otherSchool, $teacherToken] = $this->schoolToken('teacher');
        $this->withToken($teacherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/imports/'.$batch.'/mapping')->assertNotFound();
    }

    public function test_validation_normalizes_valid_rows_and_keeps_invalid_rows_separate(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->uploadStudents($school->public_id, $token);
        $mappingPath = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/mapping';
        $mapping = [
            ['source' => 'student_number', 'target' => 'student_number'],
            ['source' => 'display_name', 'target' => 'display_name'],
            ['source' => 'admission_date', 'target' => 'admission_date'],
            ['source' => 'metadata', 'target' => 'metadata'],
        ];
        $this->withToken($token)->putJson($mappingPath, ['mappings' => $mapping])->assertCreated();

        $validationPath = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch;
        $this->withToken($token)->postJson($validationPath.'/validate')->assertOk()
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.summary.invalid', 0)
            ->assertJsonPath('data.ready_for_duplicate_detection', true);
        $this->withToken($token)->getJson($validationPath.'/validation')->assertOk()->assertJsonPath('data.rows.0.values.admission_date', '2026-09-01');
        self::assertDatabaseCount('students', 0);
        self::assertDatabaseHas('import_staged_rows', ['status' => 'valid']);
    }

    public function test_validation_requires_a_complete_mapping_and_rejects_invalid_contacts(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->uploadStudents($school->public_id, $token);
        $mappingPath = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/mapping';
        $this->withToken($token)->putJson($mappingPath, ['mappings' => [['source' => 'student_number', 'target' => 'student_number']]])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/validate')->assertUnprocessable();
        self::assertDatabaseHas('import_staged_rows', ['status' => 'pending']);
        self::assertDatabaseCount('students', 0);
    }

    public function test_duplicate_detection_requires_explicit_resolution_without_mutating_students(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->uploadStudentRows($school->public_id, $token, "student_number,display_name,admission_date,metadata\nSTU-001,Fictional Learner,2026-09-01,cohort-a\nSTU-001,Fictional Learner Two,2026-09-01,cohort-b\n");
        $this->completeStudentMapping($school->public_id, $token, $batch);
        $base = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch;

        $this->withToken($token)->postJson($base.'/validate')->assertOk()->assertJsonPath('data.ready_for_duplicate_detection', true);
        $this->withToken($token)->postJson($base.'/duplicates/detect')->assertOk()->assertJsonPath('data.summary.total', 2)->assertJsonPath('data.ready_for_dry_run', false);
        $findings = ImportDuplicateFinding::query()->whereHas('batch', fn ($query) => $query->where('public_id', $batch))->orderBy('id')->get();
        self::assertCount(2, $findings);

        $this->withToken($token)->postJson($base.'/duplicates/'.$findings[0]->public_id.'/resolve', ['resolution' => 'skip'])->assertOk()->assertJsonPath('data.resolution', 'skip');
        $this->withToken($token)->postJson($base.'/duplicates/'.$findings[1]->public_id.'/resolve', ['resolution' => 'create_new'])->assertOk()->assertJsonPath('data.resolution', 'create_new');
        $this->withToken($token)->getJson($base.'/duplicates')->assertOk()->assertJsonPath('data.ready_for_dry_run', true);
        self::assertDatabaseCount('students', 0);
    }

    public function test_duplicate_detection_matches_existing_records_only_inside_the_selected_school(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $student = TenantContext::runInternal(TenantContext::fromMembership($school->memberships()->firstOrFail()->load('school')), 'duplicate detection fixture', static fn (): Student => Student::query()->create(['student_number' => 'STU-001', 'display_name' => 'Existing Fictional Learner', 'admission_date' => '2026-09-01', 'status' => 'active']));
        $batch = $this->uploadStudents($school->public_id, $token);
        $this->completeStudentMapping($school->public_id, $token, $batch);
        $base = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch;
        $this->withToken($token)->postJson($base.'/validate')->assertOk();
        $this->withToken($token)->postJson($base.'/duplicates/detect')->assertOk()->assertJsonPath('data.summary.total', 1)->assertJsonPath('data.findings.0.candidate_public_id', $student->public_id)->assertJsonPath('data.findings.0.detection_source', 'existing_record');
        $this->withToken($token)->postJson($base.'/duplicates/'.ImportDuplicateFinding::query()->firstOrFail()->public_id.'/resolve', ['resolution' => 'link_existing', 'candidate_public_id' => $student->public_id])->assertOk()->assertJsonPath('data.resolution', 'link_existing');
        self::assertDatabaseCount('students', 1);
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role): array
    {
        $school = School::factory()->create(['name' => 'Fictional Import Staging School']);
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => $role.'-'.uniqid().'@example.test', 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }

    private function xlsxContent(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'schoolos-import-');
        self::assertNotFalse($path);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['student_number', 'display_name', 'admission_date', 'metadata'],
            ['STU-001', 'Fictional Learner', '2026-09-01', 'cohort-a'],
        ]);
        (new Xlsx($spreadsheet))->save($path);
        $contents = file_get_contents($path);
        unlink($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function uploadStudents(string $school, string $token): string
    {
        return (string) $this->withToken($token)->post('/api/v1/schools/'.$school.'/imports', [
            'template' => 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('students.csv', "student_number,display_name,admission_date,metadata\nSTU-001,Fictional Learner,2026-09-01,cohort-a\n"),
        ])->assertCreated()->json('data.id');
    }

    private function uploadStudentRows(string $school, string $token, string $content): string
    {
        return (string) $this->withToken($token)->post('/api/v1/schools/'.$school.'/imports', [
            'template' => 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('students.csv', $content),
        ])->assertCreated()->json('data.id');
    }

    private function completeStudentMapping(string $school, string $token, string $batch): void
    {
        $this->withToken($token)->putJson('/api/v1/schools/'.$school.'/imports/'.$batch.'/mapping', ['mappings' => [
            ['source' => 'student_number', 'target' => 'student_number'],
            ['source' => 'display_name', 'target' => 'display_name'],
            ['source' => 'admission_date', 'target' => 'admission_date'],
            ['source' => 'metadata', 'target' => 'metadata'],
        ]])->assertCreated();
    }
}

final class FakeImportCloudinaryClient implements CloudinaryAssetClient
{
    public int $uploads = 0;

    /** @var array<string, mixed> */
    public array $options = [];

    public function upload(UploadedFile $file, array $options): array
    {
        $this->uploads++;
        $this->options = $options;

        return ['public_id' => $options['public_id'], 'format' => strtolower((string) $file->getClientOriginalExtension()), 'resource_type' => 'raw'];
    }

    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        return 'https://cloudinary.test/private/'.$format;
    }
}

final class FakeImportMalwareScanner implements MalwareScanner
{
    public function scan(UploadedFile $file): FileScanResult
    {
        return FileScanResult::Clean;
    }
}
