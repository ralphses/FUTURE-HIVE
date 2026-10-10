<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Contracts\GuardianInvitationCodeDelivery;
use App\Contexts\Registry\Domain\Models\GuardianInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGuardianInvitationCodeDelivery;
use Tests\TestCase;

final class GuardianInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_relationship_can_be_invited_and_activated_by_the_verified_guardian(): void
    {
        $delivery = new FakeGuardianInvitationCodeDelivery;
        $this->app->instance(GuardianInvitationCodeDelivery::class, $delivery);
        [$school, $admin] = $this->schoolWithAdmin();
        $guardian = UserIdentity::factory()->withPassword('guardian-password')->create(['name' => 'Fictional Guardian']);
        $guardian->contacts()->update(['verified_at' => now()]);
        $adminToken = $this->loginAndSelect($admin, $school, 'admin-password');
        $student = $this->admit($adminToken, $school);
        $relationshipPath = "/api/v1/schools/{$school->public_id}/students/{$student}/guardian-relationships";
        $relationship = $this->withToken($adminToken)->postJson($relationshipPath, [
            'guardian_id' => $guardian->public_id,
            'relationship_type' => 'parent',
        ])->assertCreated()->json('data.id');

        $this->withToken($adminToken)->postJson("{$relationshipPath}/{$relationship}/invitation")
            ->assertAccepted();
        $invitation = $delivery->invitationId;
        self::assertNotNull($invitation);

        $guardianToken = $this->login($guardian, 'guardian-password');
        $this->withToken($guardianToken)->postJson("/api/v1/guardian-invitations/{$invitation}/confirm", ['code' => $delivery->code])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('student_guardian_relationships', ['public_id' => $relationship, 'status' => 'active']);
        $this->assertNotNull(GuardianInvitation::query()->withoutGlobalScope('trusted_tenant')->where('public_id', $invitation)->value('consumed_at'));
    }

    public function test_wrong_guardian_cannot_consume_an_invitation_and_token_is_hashed(): void
    {
        $delivery = new FakeGuardianInvitationCodeDelivery;
        $this->app->instance(GuardianInvitationCodeDelivery::class, $delivery);
        [$school, $admin] = $this->schoolWithAdmin();
        $guardian = UserIdentity::factory()->create();
        $other = UserIdentity::factory()->withPassword('other-password')->create();
        $other->contacts()->update(['verified_at' => now()]);
        $adminToken = $this->loginAndSelect($admin, $school, 'admin-password');
        $student = $this->admit($adminToken, $school);
        $relationshipPath = "/api/v1/schools/{$school->public_id}/students/{$student}/guardian-relationships";
        $relationship = $this->withToken($adminToken)->postJson($relationshipPath, [
            'guardian_id' => $guardian->public_id,
            'relationship_type' => 'guardian',
        ])->json('data.id');
        $this->withToken($adminToken)->postJson("{$relationshipPath}/{$relationship}/invitation")->assertAccepted();

        $otherToken = $this->login($other, 'other-password');
        $this->withToken($otherToken)->postJson("/api/v1/guardian-invitations/{$delivery->invitationId}/confirm", ['code' => $delivery->code])->assertBadRequest();
        $invitation = GuardianInvitation::query()->withoutGlobalScope('trusted_tenant')->firstOrFail();
        self::assertNotSame($delivery->code, $invitation->getRawOriginal('code_hash'));
        self::assertSame(hash('sha256', $delivery->code), $invitation->getRawOriginal('code_hash'));
    }

    public function test_invitation_delivery_failure_revokes_challenge_without_activation(): void
    {
        $delivery = new FakeGuardianInvitationCodeDelivery;
        $delivery->shouldFail = true;
        $this->app->instance(GuardianInvitationCodeDelivery::class, $delivery);
        [$school, $admin] = $this->schoolWithAdmin();
        $guardian = UserIdentity::factory()->create();
        $adminToken = $this->loginAndSelect($admin, $school, 'admin-password');
        $student = $this->admit($adminToken, $school);
        $relationshipPath = "/api/v1/schools/{$school->public_id}/students/{$student}/guardian-relationships";
        $relationship = $this->withToken($adminToken)->postJson($relationshipPath, [
            'guardian_id' => $guardian->public_id,
            'relationship_type' => 'parent',
        ])->json('data.id');
        $this->withToken($adminToken)->postJson("{$relationshipPath}/{$relationship}/invitation")->assertAccepted();

        $this->assertDatabaseHas('guardian_invitations', ['revocation_reason' => 'delivery_unavailable']);
        $this->assertDatabaseHas('student_guardian_relationships', ['public_id' => $relationship, 'status' => 'pending']);
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithAdmin(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Guardian Access School']);
        $admin = UserIdentity::factory()->withPassword('admin-password')->create();
        $admin->contacts()->update(['verified_at' => now()]);
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $admin->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $admin->id, 'assigned_at' => now()]);

        return [$school, $admin];
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function login(UserIdentity $identity, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
    }

    private function admit(string $token, School $school): string
    {
        return $this->withToken($token)->postJson("/api/v1/schools/{$school->public_id}/students", ['student_number' => 'INV-001', 'display_name' => 'Fictional Learner'])->assertCreated()->json('data.id');
    }
}
