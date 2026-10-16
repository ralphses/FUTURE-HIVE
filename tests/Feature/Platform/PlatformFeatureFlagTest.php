<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_operations_can_manage_allowlisted_flags_and_school_overrides(): void
    {
        $school = School::factory()->create();
        [$token] = $this->platformToken('platform_ops');

        $draft = $this->withToken($token)->postJson('/api/v1/platform/feature-flags', [
            'flag_key' => 'portal.parent-dashboard',
            'label' => 'Parent dashboard',
            'value' => 'enabled',
        ])->assertCreated()->json('data.flag');

        $this->withToken($token)->postJson('/api/v1/platform/feature-flags/'.$draft['id'].'/activate')
            ->assertOk()->assertJsonPath('data.flag.status', 'active');

        $this->withToken($token)->getJson('/api/v1/platform/schools/'.$school->public_id.'/feature-flags')
            ->assertOk()->assertJsonPath('data.flags.4.key', 'portal.parent-dashboard')
            ->assertJsonPath('data.flags.4.value', 'enabled');

        $override = $this->withToken($token)->putJson('/api/v1/platform/schools/'.$school->public_id.'/feature-flags/'.$draft['id'], ['value' => 'limited'])
            ->assertCreated()->json('data.flag');

        $this->withToken($token)->postJson('/api/v1/platform/feature-flags/'.$override['id'].'/activate')->assertOk();
        $this->withToken($token)->getJson('/api/v1/platform/schools/'.$school->public_id.'/feature-flags')
            ->assertOk()->assertJsonPath('data.flags.4.value', 'limited')->assertJsonPath('data.flags.4.source', 'school_override');
    }

    public function test_unknown_values_and_unauthorized_roles_are_rejected_generically(): void
    {
        [$token] = $this->platformToken('platform_ops');
        [$schoolToken] = $this->schoolToken();

        $this->withToken($token)->postJson('/api/v1/platform/feature-flags', ['flag_key' => 'unknown.flag', 'label' => 'Unknown', 'value' => true])->assertNotFound();
        $this->withToken($schoolToken)->getJson('/api/v1/platform/feature-flags')->assertNotFound();
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
