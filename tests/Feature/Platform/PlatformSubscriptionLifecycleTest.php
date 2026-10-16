<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PlatformSubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_can_start_a_trial_and_request_time_evaluates_access_states(): void
    {
        [$school, $token, $now] = $this->configuredSchool('school_admin');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription';

        $subscription = $this->withToken($token)->postJson($base, [
            'trial_ends_at' => $now->copy()->addDays(2)->toISOString(),
            'ends_at' => $now->copy()->addDays(10)->toISOString(),
            'grace_days' => 3,
        ])->assertCreated()->assertJsonPath('data.subscription.status', 'trial')->json('data.subscription.id');

        $this->travelTo($now->copy()->addDays(3));
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.subscription.status', 'active');
        $this->travelTo($now->copy()->addDays(11));
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.subscription.status', 'grace');
        $this->travelTo($now->copy()->addDays(14));
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.subscription.status', 'expired');
        $this->travelBack();

        $this->assertDatabaseHas('platform_subscriptions', ['public_id' => $subscription, 'status' => 'trial']);
    }

    public function test_renewal_retains_history_and_cancellation_is_idempotent(): void
    {
        [$school, $token, $now] = $this->configuredSchool('proprietor');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription';
        $this->withToken($token)->postJson($base, ['ends_at' => $now->copy()->addDays(10)->toISOString()])->assertCreated();

        $this->withToken($token)->postJson($base.'/renew', ['ends_at' => $now->copy()->addDays(20)->toISOString()])
            ->assertOk()->assertJsonPath('data.subscription.status', 'active');
        $this->withToken($token)->getJson($base.'/history')->assertOk()->assertJsonCount(2, 'data.subscriptions');
        $this->withToken($token)->postJson($base.'/cancel')->assertOk()->assertJsonPath('data.subscription.status', 'cancelled');
        $this->withToken($token)->postJson($base.'/cancel')->assertOk()->assertJsonPath('data.subscription.status', 'cancelled');
    }

    public function test_expired_subscription_can_reopen_a_bounded_grace_period(): void
    {
        [$school, $token, $now] = $this->configuredSchool('school_admin');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription';
        $this->withToken($token)->postJson($base, ['ends_at' => $now->copy()->addDay()->toISOString()])->assertCreated();

        $this->travelTo($now->copy()->addDays(2));
        $this->withToken($token)->postJson($base.'/reopen-grace', ['grace_days' => 5])
            ->assertOk()->assertJsonPath('data.subscription.status', 'grace');
        $this->travelBack();
    }

    public function test_invalid_dates_and_duplicate_active_lifecycle_are_rejected(): void
    {
        [$school, $token, $now] = $this->configuredSchool('school_admin');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription';

        $this->withToken($token)->postJson($base, ['ends_at' => $now->copy()->subDay()->toISOString()])->assertUnprocessable();
        $this->withToken($token)->postJson($base, ['ends_at' => $now->copy()->addDays(10)->toISOString()])->assertCreated();
        $this->withToken($token)->postJson($base, ['ends_at' => $now->copy()->addDays(20)->toISOString()])->assertNotFound();
    }

    public function test_unprivileged_roles_cannot_read_or_manage_subscription_lifecycle(): void
    {
        [$school, $token] = $this->schoolToken('teacher');
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription';

        $this->withToken($token)->getJson($base)->assertNotFound();
        $this->withToken($token)->postJson($base, ['ends_at' => now()->addDays(10)->toISOString()])->assertNotFound();
    }

    /** @return array{0: School, 1: string, 2: Carbon} */
    private function configuredSchool(string $role): array
    {
        [$school, $token, $now] = $this->schoolToken($role);
        $base = '/api/v1/schools/'.$school->public_id.'/platform-subscription-plans';
        $plan = $this->withToken($token)->postJson($base, ['code' => 'STANDARD', 'name' => 'Standard', 'entitlement_keys' => ['academic.read', 'reports.read']])->assertCreated()->json('data.plan.id');
        $this->withToken($token)->postJson($base.'/'.$plan.'/activate')->assertOk();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/platform-entitlements/'.$plan)->assertOk();

        return [$school, $token, $now];
    }

    /** @return array{0: School, 1: string, 2: Carbon} */
    private function schoolToken(string $role): array
    {
        $now = now();
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = $role.'-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => $now]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => $now]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => $now]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token, $now];
    }
}
