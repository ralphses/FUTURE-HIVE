<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformSubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_can_create_edit_activate_and_assign_a_configuration_only_plan(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription-plans';

        $plan = $this->withToken($token)->postJson($base, [
            'code' => 'STANDARD',
            'name' => 'Standard',
            'description' => 'Core configuration',
            'entitlement_keys' => ['academic.read', 'reports.read'],
        ])->assertCreated()->assertJsonPath('data.plan.status', 'draft')->json('data.plan.id');

        $this->withToken($token)->patchJson($base.'/'.$plan, ['name' => 'Standard Plus'])
            ->assertOk()->assertJsonPath('data.plan.name', 'Standard Plus');
        $this->withToken($token)->postJson($base.'/'.$plan.'/activate')
            ->assertOk()->assertJsonPath('data.plan.status', 'active');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/platform-entitlements/'.$plan)
            ->assertOk()->assertJsonPath('data.entitlement.plan.id', $plan);

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/platform-entitlements')
            ->assertOk()->assertJsonPath('data.entitlement.plan.entitlement_keys.0', 'academic.read');
        $this->assertDatabaseHas('platform_subscription_plans', ['code' => 'STANDARD', 'status' => 'active']);
    }

    public function test_active_and_retired_plan_versions_are_immutable(): void
    {
        [$school, $token] = $this->schoolToken('proprietor');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription-plans';
        $plan = $this->withToken($token)->postJson($base, ['code' => 'IMMUTABLE', 'name' => 'Immutable', 'entitlement_keys' => ['one']])->assertCreated()->json('data.plan.id');

        $this->withToken($token)->postJson($base.'/'.$plan.'/activate')->assertOk();
        $this->withToken($token)->patchJson($base.'/'.$plan, ['name' => 'Changed'])->assertNotFound();
        $this->withToken($token)->postJson($base.'/'.$plan.'/retire')->assertOk()->assertJsonPath('data.plan.status', 'retired');
        $this->withToken($token)->postJson($base.'/'.$plan.'/activate')->assertNotFound();
    }

    public function test_invalid_entitlements_and_duplicate_active_code_are_rejected(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription-plans';

        $this->withToken($token)->postJson($base, ['code' => 'INVALID', 'name' => 'Invalid', 'entitlement_keys' => ['Bad Key']])->assertUnprocessable();
        $first = $this->withToken($token)->postJson($base, ['code' => 'DUPLICATE', 'name' => 'One', 'entitlement_keys' => ['one']])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/'.$first.'/activate')->assertOk();
        $second = $this->withToken($token)->postJson($base, ['code' => 'DUPLICATE', 'name' => 'Two', 'entitlement_keys' => ['two']])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/'.$second.'/activate')->assertNotFound();
    }

    public function test_unprivileged_school_roles_cannot_manage_or_read_platform_plans(): void
    {
        [$school, $token] = $this->schoolToken('teacher');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/platform-subscription-plans')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/platform-entitlements')->assertNotFound();
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = $role.'-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }
}
