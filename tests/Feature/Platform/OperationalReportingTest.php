<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Files\CloudinaryAssetClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class OperationalReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(CloudinaryAssetClient::class, new FakeOperationalReportCloudinaryClient);
    }

    public function test_approved_leadership_can_read_all_bounded_operational_reports(): void
    {
        [$school, $token] = $this->schoolToken('principal', 'operational-report@example.com', 'operational-report-password');

        foreach (['enrollment', 'attendance', 'academic', 'finance'] as $report) {
            $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/operational-reports/'.$report)
                ->assertOk()
                ->assertJsonPath('data.school_id', $school->public_id)
                ->assertJsonPath('data.id', 'operational-report-'.$report)
                ->assertJsonStructure(['data' => ['request_id', 'date_range', 'report', 'generated_at']])
                ->assertJsonMissingPath('data.report.raw_scores')
                ->assertJsonMissingPath('data.report.students')
                ->assertJsonMissingPath('data.report.total_minor_units')
                ->assertJsonMissingPath('data.internal_ids');
        }
    }

    public function test_teachers_cannot_read_operational_reports(): void
    {
        [$school, $token] = $this->schoolToken('teacher', 'operational-report-teacher@example.com', 'operational-report-teacher-password');

        $this->withToken($token)
            ->getJson('/api/v1/schools/'.$school->public_id.'/operational-reports/enrollment')
            ->assertNotFound();
    }

    public function test_leadership_can_export_and_manage_idempotent_report_schedules(): void
    {
        [$school, $token] = $this->schoolToken('principal', 'operational-export@example.com', 'operational-export-password');

        $this->withToken($token)
            ->getJson('/api/v1/schools/'.$school->public_id.'/operational-reports/export?report=attendance&format=csv')
            ->assertOk()
            ->assertJsonPath('data.export.format', 'csv')
            ->assertJsonPath('data.export.report', 'attendance')
            ->assertJsonMissingPath('data.export.provider_url');

        $payload = ['report' => 'attendance', 'format' => 'xlsx', 'frequency' => 'weekly', 'idempotency_key' => 'schedule-1'];
        $first = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/operational-report-schedules', $payload)->assertCreated();
        $second = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/operational-report-schedules', $payload)->assertCreated();
        self::assertSame($first->json('data.schedule.id'), $second->json('data.schedule.id'));

        $schedule = $first->json('data.schedule.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/operational-report-schedules/'.$schedule.'/activate')->assertOk()->assertJsonPath('data.schedule.status', 'active');
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/operational-report-schedules/'.$schedule)->assertOk()->assertJsonPath('data.schedule.status', 'active');
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role, string $login, string $password): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }
}

final class FakeOperationalReportCloudinaryClient implements CloudinaryAssetClient
{
    public function upload(UploadedFile $file, array $options): array
    {
        return ['public_id' => (string) ($options['public_id'] ?? 'test-upload'), 'format' => 'bin', 'resource_type' => 'raw'];
    }

    public function uploadPath(string $path, array $options): array
    {
        return ['public_id' => (string) ($options['public_id'] ?? 'test-report'), 'format' => (string) ($options['format'] ?? 'bin'), 'resource_type' => 'raw'];
    }

    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        return 'https://cloudinary.test/private/'.$format;
    }
}
