<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Files\CloudinaryAssetClient;
use App\Support\Files\FileScanResult;
use App\Support\Files\MalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ImportDryRunTest extends TestCase
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
        $cloudinary = new FakeImportDryRunCloudinaryClient;
        $this->app->instance(FakeImportDryRunCloudinaryClient::class, $cloudinary);
        $this->app->instance(CloudinaryAssetClient::class, $cloudinary);
        $this->app->singleton(MalwareScanner::class, FakeImportDryRunMalwareScanner::class);
    }

    public function test_ready_dry_run_reports_safe_plans_and_is_idempotent(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->uploadAndPrepare($school->public_id, $token);
        $path = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/dry-run';

        $first = $this->withToken($token)->postJson($path)->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.ready_for_commit', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.create_new', 1)
            ->assertJsonPath('data.planned.0.record_type', 'student')
            ->assertJsonPath('data.planned.0.row_number', 2);
        $reportId = (string) $first->json('data.id');

        $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('data.id', $reportId);
        $this->withToken($token)->postJson($path)->assertOk()->assertJsonPath('data.id', fn (string $id): bool => $id !== $reportId);
        self::assertDatabaseCount('import_dry_run_reports', 1);
        self::assertDatabaseCount('students', 0);
    }

    public function test_invalid_rows_produce_a_blocked_report_without_raw_values(): void
    {
        [$school, $token] = $this->schoolToken('principal');
        $batch = $this->upload($school->public_id, $token, "student_number,display_name,admission_date,metadata\n,Fictional Learner,not-a-date,cohort-a\n");
        $this->completeMapping($school->public_id, $token, $batch);
        $base = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch;
        $this->withToken($token)->postJson($base.'/validate')->assertOk();

        $this->withToken($token)->postJson($base.'/dry-run')->assertOk()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.ready_for_commit', false)
            ->assertJsonPath('data.blockers.0', 'invalid_rows')
            ->assertJsonMissing(['not-a-date'])
            ->assertJsonMissing(['Fictional Learner']);
    }

    public function test_dry_run_requires_authorized_selected_school_context(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->uploadAndPrepare($school->public_id, $token);
        [$otherSchool, $otherToken] = $this->schoolToken('teacher');

        $this->withToken($otherToken)->postJson('/api/v1/schools/'.$otherSchool->public_id.'/imports/'.$batch.'/dry-run')->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/dry-run')->assertOk();
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role): array
    {
        $school = School::factory()->create(['name' => 'Fictional Dry Run School']);
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => $role.'-'.uniqid().'@example.test', 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }

    private function uploadAndPrepare(string $school, string $token): string
    {
        $batch = $this->upload($school, $token, "student_number,display_name,admission_date,metadata\nSTU-001,Fictional Learner,2026-09-01,cohort-a\n");
        $this->completeMapping($school, $token, $batch);
        $this->withToken($token)->postJson('/api/v1/schools/'.$school.'/imports/'.$batch.'/validate')->assertOk();

        return $batch;
    }

    private function upload(string $school, string $token, string $content): string
    {
        return (string) $this->withToken($token)->post('/api/v1/schools/'.$school.'/imports', [
            'template' => 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('students.csv', $content),
        ])->assertCreated()->json('data.id');
    }

    private function completeMapping(string $school, string $token, string $batch): void
    {
        $this->withToken($token)->putJson('/api/v1/schools/'.$school.'/imports/'.$batch.'/mapping', ['mappings' => [
            ['source' => 'student_number', 'target' => 'student_number'],
            ['source' => 'display_name', 'target' => 'display_name'],
            ['source' => 'admission_date', 'target' => 'admission_date'],
            ['source' => 'metadata', 'target' => 'metadata'],
        ]])->assertCreated();
    }
}

final class FakeImportDryRunCloudinaryClient implements CloudinaryAssetClient
{
    public function upload(UploadedFile $file, array $options): array
    {
        return ['public_id' => $options['public_id'], 'format' => 'csv', 'resource_type' => 'raw'];
    }

    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        return 'https://cloudinary.test/private/'.$format;
    }
}

final class FakeImportDryRunMalwareScanner implements MalwareScanner
{
    public function scan(UploadedFile $file): FileScanResult
    {
        return FileScanResult::Clean;
    }
}
