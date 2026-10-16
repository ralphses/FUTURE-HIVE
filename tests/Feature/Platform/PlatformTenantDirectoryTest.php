<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformTenantDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_read_safe_directory_projections_without_school_context(): void
    {
        $school = School::factory()->create(['name' => 'Fictional Academy']);
        [$token] = $this->platformToken('platform_admin');

        $this->withToken($token)->getJson('/api/v1/platform/schools')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $school->public_id)
            ->assertJsonPath('data.0.name', 'Fictional Academy')
            ->assertJsonPath('data.0.subscription', null)
            ->assertJsonMissing(['email' => 'secret@example.test'])
            ->assertJsonMissing(['internal_id' => $school->id]);

        $this->withToken($token)->getJson('/api/v1/platform/schools/'.$school->public_id)
            ->assertOk()
            ->assertJsonPath('data.school.id', (string) $school->public_id);

        $this->withToken($token)->getJson('/api/v1/platform/schools/'.$school->public_id.'/entitlements')
            ->assertOk()
            ->assertJsonPath('data.entitlement.status', 'unassigned');
    }

    public function test_platform_directory_filters_are_bounded_and_deterministically_ordered(): void
    {
        School::factory()->create(['name' => 'Zulu Academy', 'status' => 'suspended']);
        School::factory()->create(['name' => 'Alpha Academy', 'status' => 'active']);
        [$token] = $this->platformToken('platform_admin');

        $this->withToken($token)->getJson('/api/v1/platform/schools?status=active&setup_status=pending&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.name', 'Alpha Academy');

        $this->withToken($token)->getJson('/api/v1/platform/schools?status=invalid')
            ->assertUnprocessable();
    }

    public function test_only_platform_school_read_permission_can_access_the_directory(): void
    {
        $school = School::factory()->create();
        [$platformOpsToken] = $this->platformToken('platform_ops');
        [$schoolToken] = $this->schoolToken();

        $this->withToken($platformOpsToken)->getJson('/api/v1/platform/schools')->assertNotFound();
        $this->withToken($schoolToken)->getJson('/api/v1/platform/schools/'.$school->public_id)->assertNotFound();
    }

    public function test_directory_does_not_cross_resolve_guessed_school_identifiers(): void
    {
        [$token] = $this->platformToken('platform_admin');

        $this->withToken($token)->getJson('/api/v1/platform/schools/'.fake()->uuid())->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/platform/schools/'.fake()->uuid().'/entitlements')->assertNotFound();
    }

    /** @return array{0: string, 1: UserIdentity} */
    private function platformToken(string $roleKey): array
    {
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = $roleKey.'-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        PlatformRoleAssignment::query()->create([
            'user_id' => $identity->id,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
            'assigned_by' => $identity->id,
            'assigned_at' => now(),
        ]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])
            ->assertOk()
            ->json('data.access_token');

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
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])
            ->assertOk()
            ->json('data.access_token');

        return [$token, $identity];
    }
}
