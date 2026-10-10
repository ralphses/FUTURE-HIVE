<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

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

final class StudentDocumentTest extends TestCase
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
        config(['auth.jwt.private_key' => $privateKey, 'auth.jwt.public_keys' => ['test-key' => (string) ($details['key'] ?? '')], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api', 'services.cloudinary.download_ttl' => 300, 'services.cloudinary.upload_prefix' => 'schoolos/schools']);
        $this->app->singleton(CloudinaryAssetClient::class, FakeStudentDocumentCloudinaryClient::class);
        $this->app->singleton(MalwareScanner::class, FakeStudentDocumentMalwareScanner::class);
    }

    public function test_clean_document_is_scanned_stored_privately_and_downloadable_by_signed_link(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $token = $this->loginAndSelect($identity, $school);
        $student = $this->admit($token, $school);
        $path = $this->documentsPath($school, $student);

        $response = $this->withToken($token)->post($path, ['category' => 'birth_certificate', 'document' => UploadedFile::fake()->create('fictional-birth.pdf', 20, 'application/pdf')]);

        $response->assertCreated()->assertJsonPath('data.category', 'birth_certificate')->assertJsonMissingPath('data.cloudinary_public_id');
        $document = (string) $response->json('data.id');
        self::assertStringContainsString('/api/v1/files/download', (string) $response->json('data.download_url'));
        $client = $this->app->make(CloudinaryAssetClient::class);
        self::assertInstanceOf(FakeStudentDocumentCloudinaryClient::class, $client);
        self::assertSame('authenticated', $client->options['type']);
        self::assertSame('raw', $client->options['resource_type']);
        self::assertStringContainsString('schoolos/schools/'.$school->public_id.'/', (string) $client->options['public_id']);
        self::assertStringNotContainsString('fictional-birth', (string) $client->options['public_id']);

        $this->withToken($token)->getJson($path.'/'.$document)->assertOk()->assertJsonPath('data.id', $document);
    }

    public function test_infected_document_is_rejected_before_cloudinary_and_revocation_retains_history(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $token = $this->loginAndSelect($identity, $school);
        $student = $this->admit($token, $school);
        $path = $this->documentsPath($school, $student);
        $scanner = $this->app->make(MalwareScanner::class);
        self::assertInstanceOf(FakeStudentDocumentMalwareScanner::class, $scanner);
        $scanner->result = FileScanResult::Infected;

        $this->withToken($token)->post($path, ['category' => 'passport_photo', 'document' => UploadedFile::fake()->image('infected.png')])->assertStatus(500);
        $client = $this->app->make(CloudinaryAssetClient::class);
        self::assertInstanceOf(FakeStudentDocumentCloudinaryClient::class, $client);
        self::assertSame(0, $client->uploads);

        $scanner->result = FileScanResult::Clean;
        $document = $this->withToken($token)->post($path, ['category' => 'transfer_letter', 'document' => UploadedFile::fake()->create('fictional-transfer.pdf', 20, 'application/pdf')])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($path.'/'.$document.'/revoke')->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withToken($token)->getJson($path.'/'.$document)->assertNotFound();
        self::assertDatabaseHas('student_documents', ['public_id' => $document, 'status' => 'revoked']);
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithAdmin(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Student Documents Academy']);
        $identity = UserIdentity::factory()->withPassword('document-password')->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'document-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function admit(string $token, School $school): string
    {
        return $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'DOC-001', 'display_name' => 'Fictional Document Learner'])->assertCreated()->json('data.id');
    }

    private function documentsPath(School $school, string $student): string
    {
        return '/api/v1/schools/'.$school->public_id.'/students/'.$student.'/documents';
    }
}

final class FakeStudentDocumentCloudinaryClient implements CloudinaryAssetClient
{
    public int $uploads = 0;

    /** @var array<string, mixed> */
    public array $options = [];

    public function upload(UploadedFile $file, array $options): array
    {
        $this->uploads++;
        $this->options = $options;

        return ['public_id' => $options['public_id'], 'format' => 'pdf', 'resource_type' => 'raw'];
    }

    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        return 'https://cloudinary.invalid/authenticated/'.$format;
    }
}

final class FakeStudentDocumentMalwareScanner implements MalwareScanner
{
    public FileScanResult $result = FileScanResult::Clean;

    public function scan(UploadedFile $file): FileScanResult
    {
        return $this->result;
    }
}
