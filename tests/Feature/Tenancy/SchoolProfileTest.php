<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

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

final class SchoolProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $this->privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->publicKey = (string) ($details['key'] ?? '');
        config([
            'auth.jwt.private_key' => $this->privateKey,
            'auth.jwt.public_keys' => ['test-key' => $this->publicKey],
            'auth.jwt.current_kid' => 'test-key',
            'auth.jwt.issuer' => 'https://schoolos.test',
            'auth.jwt.audience' => 'schoolos-api',
        ]);
        $this->app->singleton(CloudinaryAssetClient::class, FakeProfileCloudinaryClient::class);
        $this->app->bind(MalwareScanner::class, FakeProfileMalwareScanner::class);
    }

    public function test_profile_is_backfilled_and_can_be_read_and_updated_in_trusted_context(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $token = $this->loginAndSelect($identity, $school);

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/profile')
            ->assertOk()
            ->assertJsonPath('data.school_id', $school->public_id)
            ->assertJsonPath('data.country', 'NG')
            ->assertJsonPath('data.timezone', 'Africa/Lagos')
            ->assertJsonPath('data.logo.available', false);

        $this->withToken($token)->putJson('/api/v1/schools/'.$school->public_id.'/profile', [
            'contact' => ' OWNER@Example.com ',
            'city' => 'Lagos',
            'timezone' => 'Europe/London',
            'school_id' => 'forged',
            'updated_at' => '2030-01-01T00:00:00Z',
        ])->assertOk()
            ->assertJsonPath('data.contact', 'owner@example.com')
            ->assertJsonPath('data.contact_type', 'email')
            ->assertJsonPath('data.city', 'Lagos')
            ->assertJsonPath('data.timezone', 'Europe/London');
    }

    public function test_profile_requires_permission_and_rejects_cross_school_context(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $otherSchool = School::factory()->create(['name' => 'Fictional Other Academy']);
        $token = $this->loginAndSelect($identity, $school);

        $this->withToken($token)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/profile')->assertNotFound();
        $this->withToken($token)->putJson('/api/v1/schools/'.$school->public_id.'/profile', ['timezone' => 'Not/AZone'])
            ->assertUnprocessable();
    }

    public function test_logo_upload_is_scanned_and_stored_as_a_private_school_scoped_asset(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $token = $this->loginAndSelect($identity, $school);

        $response = $this->withToken($token)->post('/api/v1/schools/'.$school->public_id.'/profile/logo', [
            'logo' => UploadedFile::fake()->image('fictional-logo.png'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.logo.available', true)
            ->assertJsonMissingPath('data.logo.secure_url');
        $publicId = (string) $response->json('data.logo.download_url');
        self::assertStringContainsString('/api/v1/files/download', $publicId);

        $client = $this->app->make(CloudinaryAssetClient::class);
        self::assertInstanceOf(FakeProfileCloudinaryClient::class, $client);
        self::assertSame('authenticated', $client->options['type']);
        self::assertSame('raw', $client->options['resource_type']);
        self::assertStringContainsString('schoolos/schools/'.$school->public_id.'/', (string) $client->options['public_id']);
        self::assertStringNotContainsString('fictional-logo', (string) $client->options['public_id']);
    }

    public function test_logo_scanner_failure_prevents_cloudinary_upload(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $this->app->instance(MalwareScanner::class, new FakeProfileMalwareScanner(FileScanResult::Infected));
        $token = $this->loginAndSelect($identity, $school);

        $this->withToken($token)->post('/api/v1/schools/'.$school->public_id.'/profile/logo', [
            'logo' => UploadedFile::fake()->image('infected.png'),
        ])->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $client = $this->app->make(CloudinaryAssetClient::class);
        self::assertInstanceOf(FakeProfileCloudinaryClient::class, $client);
        self::assertSame(0, $client->uploads);
    }

    public function test_logo_can_be_removed_without_exposing_cloudinary_urls(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $token = $this->loginAndSelect($identity, $school);
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/profile/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        $this->withToken($token)->deleteJson('/api/v1/schools/'.$school->public_id.'/profile/logo')
            ->assertOk()
            ->assertJsonPath('data.logo.available', false)
            ->assertJsonMissingPath('data.logo.secure_url');
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithAdmin(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Profile Academy']);
        $identity = UserIdentity::factory()->withPassword('profile-password')->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create([
            'school_membership_id' => $membership->id,
            'role_id' => Role::query()->where('key', 'school_admin')->value('id'),
            'assigned_by' => $identity->id,
            'assigned_at' => now(),
        ]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => 'profile-password',
        ])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}

final class FakeProfileCloudinaryClient implements CloudinaryAssetClient
{
    /** @var array<string, mixed> */
    public array $options = [];

    public int $uploads = 0;

    public function upload(UploadedFile $file, array $options): array
    {
        $this->uploads++;
        $this->options = $options;

        return [
            'public_id' => $options['public_id'],
            'format' => 'png',
            'resource_type' => 'raw',
        ];
    }

    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        return 'https://res.cloudinary.test/'.$publicId.'.'.$format;
    }
}

final class FakeProfileMalwareScanner implements MalwareScanner
{
    public function __construct(private readonly FileScanResult $result = FileScanResult::Clean) {}

    public function scan(UploadedFile $file): FileScanResult
    {
        return $this->result;
    }
}
