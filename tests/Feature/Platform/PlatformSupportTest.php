<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_operations_can_manage_safe_support_cases(): void
    {
        $school = School::factory()->create(['name' => 'Fictional Academy']);
        [$token] = $this->platformToken('platform_ops');

        $response = $this->withToken($token)->postJson('/api/v1/platform/schools/'.$school->public_id.'/support-cases', [
            'subject' => 'Import diagnostics',
            'category' => 'import',
            'severity' => 'medium',
            'reason_code' => 'mapping.invalid',
        ])->assertCreated();

        $case = $response->json('data.case');
        $this->assertSame('open', $case['status']);
        $this->assertSame((string) $school->public_id, $case['school']);
        $this->assertArrayNotHasKey('internal_id', $case);
        $this->assertArrayNotHasKey('email', $case);

        $this->withToken($token)->postJson('/api/v1/platform/support-cases/'.$case['id'].'/status', ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.case.status', 'in_progress');
        $this->withToken($token)->getJson('/api/v1/platform/support-cases/'.$case['id'])
            ->assertOk()->assertJsonPath('data.case.subject', 'Import diagnostics');
    }

    public function test_access_requests_are_idempotent_and_maker_checker_is_enforced(): void
    {
        $school = School::factory()->create();
        [$opsToken] = $this->platformToken('platform_ops');
        [$adminToken] = $this->platformToken('platform_admin');
        $case = $this->withToken($opsToken)->postJson('/api/v1/platform/schools/'.$school->public_id.'/support-cases', [
            'subject' => 'Safe diagnostics', 'category' => 'technical', 'severity' => 'low',
        ])->json('data.case');
        $payload = [
            'purpose' => 'Inspect bounded import diagnostics',
            'scope' => 'import.diagnostics',
            'expires_at' => now()->addHours(2)->toISOString(),
            'idempotency_key' => 'support-'.Str::random(24),
        ];

        $first = $this->withToken($opsToken)->postJson('/api/v1/platform/support-cases/'.$case['id'].'/access-grants', $payload)->assertCreated()->json('data.access_grant');
        $second = $this->withToken($opsToken)->postJson('/api/v1/platform/support-cases/'.$case['id'].'/access-grants', $payload)->assertCreated()->json('data.access_grant');
        $this->assertSame($first['id'], $second['id']);

        $this->withToken($opsToken)->postJson('/api/v1/platform/support-access/'.$first['id'].'/approve')->assertNotFound();
        $this->withToken($adminToken)->postJson('/api/v1/platform/support-access/'.$first['id'].'/approve')
            ->assertOk()->assertJsonPath('data.access_grant.status', 'approved');
    }

    public function test_repeated_revoke_is_safe_and_unauthorized_school_users_are_denied(): void
    {
        $school = School::factory()->create();
        [$opsToken] = $this->platformToken('platform_ops');
        [$adminToken] = $this->platformToken('platform_admin');
        [$schoolToken] = $this->schoolToken();
        $case = $this->withToken($opsToken)->postJson('/api/v1/platform/schools/'.$school->public_id.'/support-cases', [
            'subject' => 'Access review', 'category' => 'access', 'severity' => 'high',
        ])->json('data.case');
        $grant = $this->withToken($opsToken)->postJson('/api/v1/platform/support-cases/'.$case['id'].'/access-grants', [
            'purpose' => 'Review access issue', 'scope' => 'metadata.read', 'expires_at' => now()->addHour()->toISOString(), 'idempotency_key' => 'revoke-'.Str::random(24),
        ])->json('data.access_grant');
        $this->withToken($adminToken)->postJson('/api/v1/platform/support-access/'.$grant['id'].'/approve')->assertOk();
        $this->withToken($adminToken)->postJson('/api/v1/platform/support-access/'.$grant['id'].'/revoke')->assertOk()->assertJsonPath('data.access_grant.status', 'revoked');
        $this->withToken($adminToken)->postJson('/api/v1/platform/support-access/'.$grant['id'].'/revoke')->assertOk()->assertJsonPath('data.access_grant.status', 'revoked');
        $this->withToken($schoolToken)->getJson('/api/v1/platform/support-cases')->assertNotFound();
    }

    public function test_inactive_schools_cannot_receive_support_cases(): void
    {
        $school = School::factory()->create(['status' => 'suspended']);
        [$token] = $this->platformToken('platform_ops');

        $this->withToken($token)->postJson('/api/v1/platform/schools/'.$school->public_id.'/support-cases', [
            'subject' => 'Unavailable school', 'category' => 'other', 'severity' => 'low',
        ])->assertNotFound();
    }

    /** @return array{0: string, 1: UserIdentity} */
    private function platformToken(string $roleKey): array
    {
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = $roleKey.'-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        PlatformRoleAssignment::query()->create(['user_id' => $identity->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');

        return [$token, $identity];
    }

    /** @return array{0: string, 1: UserIdentity} */
    private function schoolToken(): array
    {
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = 'school-user-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        $school = School::factory()->create();
        $membership = $school->memberships()->create(['user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        $membership->roleAssignments()->create(['role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');

        return [$token, $identity];
    }
}
