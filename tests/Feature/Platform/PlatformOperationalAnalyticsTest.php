<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformOperationalAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_operations_can_read_aggregate_and_school_analytics(): void
    {
        $school = School::factory()->create(['name' => 'Alpha Academy']);
        [$token] = $this->platformToken('platform_ops');

        $this->withToken($token)->getJson('/api/v1/platform/operational-analytics?period_start=2026-10-01&period_end=2026-10-15')
            ->assertOk()
            ->assertJsonPath('data.report.id', 'platform-operational-analytics')
            ->assertJsonPath('data.schools.total', 1)
            ->assertJsonMissing(['internal_id' => $school->id]);

        $this->withToken($token)->getJson('/api/v1/platform/operational-analytics/schools?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.school.id', (string) $school->public_id)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonMissing(['email' => 'secret@example.test']);
    }

    public function test_school_users_and_platform_support_users_cannot_read_analytics(): void
    {
        School::factory()->create();
        [$supportToken] = $this->platformToken('platform_support');
        [$schoolToken] = $this->schoolToken();

        $this->withToken($supportToken)->getJson('/api/v1/platform/operational-analytics')->assertNotFound();
        $this->withToken($schoolToken)->getJson('/api/v1/platform/operational-analytics/schools')->assertNotFound();
    }

    public function test_filters_and_period_bounds_are_validated(): void
    {
        [$token] = $this->platformToken('platform_admin');

        $this->withToken($token)->getJson('/api/v1/platform/operational-analytics/schools?status=invalid')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/platform/operational-analytics?period_start=2026-01-01&period_end=2027-12-31')->assertNotFound();
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
