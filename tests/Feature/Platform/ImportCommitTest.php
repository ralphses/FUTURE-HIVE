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

final class ImportCommitTest extends TestCase
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
        config(['auth.jwt.private_key' => $privateKey, 'auth.jwt.public_keys' => ['test-key' => (string) ($details['key'] ?? '')], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api']);
        $cloudinary = new FakeImportCommitCloudinaryClient;
        $this->app->instance(CloudinaryAssetClient::class, $cloudinary);
        $this->app->singleton(MalwareScanner::class, FakeImportCommitMalwareScanner::class);
    }

    public function test_ready_student_import_is_committed_once_and_returns_the_same_result(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->prepare($school->public_id, $token, 'STU-001,Fictional Learner,2026-09-01,');
        $path = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/commit';

        $first = $this->withToken($token)->postJson($path, ['confirm' => 'COMMIT'])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.counts.created', 1);
        $commitId = (string) $first->json('data.id');

        $this->withToken($token)->postJson($path, ['confirm' => 'COMMIT'])->assertOk()->assertJsonPath('data.id', $commitId);
        self::assertDatabaseCount('students', 1);
        self::assertDatabaseCount('import_commits', 1);
        self::assertDatabaseCount('import_commit_rows', 1);
    }

    public function test_commit_requires_confirmation_and_ready_dry_run(): void
    {
        [$school, $token] = $this->schoolToken('principal');
        $batch = $this->upload($school->public_id, $token, 'STU-002,Fictional Learner,2026-09-01,');
        $path = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/commit';

        $this->withToken($token)->postJson($path, ['confirm' => 'NO'])->assertStatus(422);
        $this->withToken($token)->postJson($path, ['confirm' => 'COMMIT'])->assertStatus(422);
        self::assertDatabaseCount('students', 0);
        self::assertDatabaseCount('import_commits', 0);
    }

    public function test_guardian_create_new_is_reported_without_creating_an_identity(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $batch = $this->prepareGuardian($school->public_id, $token);
        $response = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/commit', ['confirm' => 'COMMIT'])->assertOk();

        $response->assertJsonPath('data.status', 'completed_with_errors')->assertJsonPath('data.rows.0.error_code', 'create_not_supported');
        self::assertDatabaseCount('users', 1);
        self::assertDatabaseCount('student_guardian_relationships', 0);
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role): array
    {
        $school = School::factory()->create(['name' => 'Fictional Import School']);
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => $role.'-'.uniqid().'@example.test', 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }

    private function prepare(string $school, string $token, string $row): string
    {
        $batch = $this->upload($school, $token, $row);
        $this->mapping($school, $token, $batch, ['student_number', 'display_name', 'admission_date', 'metadata']);
        $base = '/api/v1/schools/'.$school.'/imports/'.$batch;
        $this->withToken($token)->postJson($base.'/validate')->assertOk();
        $this->withToken($token)->postJson($base.'/duplicates/detect')->assertOk();
        $this->withToken($token)->postJson($base.'/dry-run')->assertOk()->assertJsonPath('data.status', 'ready');

        return $batch;
    }

    private function prepareGuardian(string $school, string $token): string
    {
        $batch = $this->upload($school, $token, 'STU-003,owner@example.test,Fictional Guardian,parent');
        $this->mapping($school, $token, $batch, ['student_number', 'guardian_contact', 'guardian_display_name', 'relationship_type']);
        $base = '/api/v1/schools/'.$school.'/imports/'.$batch;
        $this->withToken($token)->postJson($base.'/validate')->assertOk();
        $this->withToken($token)->postJson($base.'/duplicates/detect')->assertOk();
        $this->withToken($token)->postJson($base.'/dry-run')->assertOk()->assertJsonPath('data.status', 'ready');

        return $batch;
    }

    private function upload(string $school, string $token, string $row): string
    {
        return (string) $this->withToken($token)->post('/api/v1/schools/'.$school.'/imports', [
            'template' => str_contains($row, 'owner@example') ? 'guardians' : 'students',
            'version' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('import.csv', str_contains($row, 'owner@example') ? "student_number,guardian_contact,guardian_display_name,relationship_type\n$row\n" : "student_number,display_name,admission_date,metadata\n$row\n"),
        ])->assertCreated()->json('data.id');
    }

    /** @param list<string> $fields */
    private function mapping(string $school, string $token, string $batch, array $fields): void
    {
        $this->withToken($token)->putJson('/api/v1/schools/'.$school.'/imports/'.$batch.'/mapping', ['mappings' => array_map(fn (string $field): array => ['source' => $field, 'target' => $field], $fields)])->assertCreated();
    }
}

final class FakeImportCommitCloudinaryClient implements CloudinaryAssetClient
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

final class FakeImportCommitMalwareScanner implements MalwareScanner
{
    public function scan(UploadedFile $file): FileScanResult
    {
        return FileScanResult::Clean;
    }
}
