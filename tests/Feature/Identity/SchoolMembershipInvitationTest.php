<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Application\Actions\RevokeSchoolMembershipAction;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolInvitation;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SchoolMembershipInvitationTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $this->privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key'] ?? null);
        $this->publicKey = $details['key'];

        config([
            'auth.jwt.private_key' => $this->privateKey,
            'auth.jwt.public_keys' => ['test-key' => $this->publicKey],
            'auth.jwt.current_kid' => 'test-key',
            'auth.jwt.issuer' => 'https://schoolos.test',
            'auth.jwt.audience' => 'schoolos-api',
        ]);
    }

    public function test_owner_can_invite_a_verified_identity_and_token_is_not_persisted(): void
    {
        [$school, $owner] = $this->schoolWithOwner();
        $invitee = $this->identity('invitee@example.com', 'invitee-password');
        $token = $this->login($owner, 'owner-password')->json('data.access_token');

        $response = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => ' INVITEE@EXAMPLE.COM ',
            'school_id' => 999999,
            'invitee_id' => 999999,
            'is_owner' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.invitation_id', fn (mixed $value): bool => is_string($value))
            ->assertJsonPath('data.token', fn (mixed $value): bool => is_string($value) && strlen($value) === 64);

        $invitation = SchoolInvitation::query()->firstOrFail();
        self::assertSame($invitee->id, $invitation->invitee_id);
        self::assertNotSame($response->json('data.token'), $invitation->getRawOriginal('token_hash'));
        self::assertSame(hash('sha256', $response->json('data.token')), $invitation->getRawOriginal('token_hash'));
        self::assertSame('pending', $invitation->status);
        self::assertFalse((bool) $invitation->getAttribute('is_owner'));
    }

    public function test_invitee_can_accept_and_list_memberships_across_multiple_schools(): void
    {
        [$firstSchool, $owner] = $this->schoolWithOwner();
        $secondSchool = School::factory()->create(['name' => 'Second Fictional School']);
        SchoolMembership::create([
            'school_id' => $secondSchool->id,
            'user_id' => $owner->id,
            'is_owner' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $invitee = $this->identity('multi-school@example.com', 'invitee-password');
        $ownerToken = $this->login($owner, 'owner-password')->json('data.access_token');
        $issue = $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$firstSchool->public_id.'/invitations', [
            'contact' => 'multi-school@example.com',
        ])->assertCreated();

        $inviteeToken = $this->login($invitee, 'invitee-password')->json('data.access_token');
        $this->withToken($inviteeToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/accept', [
            'token' => $issue->json('data.token'),
        ])->assertOk();

        $this->withToken($inviteeToken)->getJson('/api/v1/me/memberships')
            ->assertOk()
            ->assertJsonCount(1, 'data.memberships');

        self::assertSame(1, SchoolMembership::query()->where('user_id', $invitee->id)->count());
    }

    public function test_non_owner_cannot_create_or_revoke_an_invitation(): void
    {
        [$school, $owner] = $this->schoolWithOwner();
        $member = $this->identity('member@example.com', 'member-password');
        $target = $this->identity('target@example.com', 'target-password');
        SchoolMembership::create([
            'school_id' => $school->id,
            'user_id' => $member->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $memberToken = $this->login($member, 'member-password')->json('data.access_token');

        $this->withToken($memberToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'other@example.com',
        ])->assertNotFound();

        $ownerToken = $this->login($owner, 'owner-password')->json('data.access_token');
        $issue = $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'target@example.com',
        ])->assertCreated();

        $this->withToken($memberToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/revoke')
            ->assertNotFound();
    }

    public function test_invitation_acceptance_requires_the_invited_identity_and_correct_token(): void
    {
        [$school, $owner] = $this->schoolWithOwner();
        $invitee = $this->identity('right@example.com', 'right-password');
        $other = $this->identity('wrong@example.com', 'wrong-password');
        $ownerToken = $this->login($owner, 'owner-password')->json('data.access_token');
        $issue = $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'right@example.com',
        ])->assertCreated();

        $wrongToken = $this->login($other, 'wrong-password')->json('data.access_token');
        $this->withToken($wrongToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/accept', [
            'token' => $issue->json('data.token'),
        ])->assertNotFound();

        $rightToken = $this->login($invitee, 'right-password')->json('data.access_token');
        $this->withToken($rightToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/accept', [
            'token' => str_repeat('a', 64),
        ])->assertNotFound();

        self::assertDatabaseCount('school_memberships', 1);
    }

    public function test_expired_revoked_and_replayed_invitations_are_not_usable(): void
    {
        [$school, $owner] = $this->schoolWithOwner();
        $invitee = $this->identity('expired@example.com', 'expired-password');
        $ownerToken = $this->login($owner, 'owner-password')->json('data.access_token');
        $issue = $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'expired@example.com',
        ])->assertCreated();
        SchoolInvitation::query()->update(['expires_at' => now()->subSecond()]);
        $inviteeToken = $this->login($invitee, 'expired-password')->json('data.access_token');
        $this->withToken($inviteeToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/accept', [
            'token' => $issue->json('data.token'),
        ])->assertNotFound();
        self::assertSame('expired', SchoolInvitation::query()->firstOrFail()->status);

        $activeInvitee = $this->identity('revoked@example.com', 'revoked-password');
        $issue = $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'revoked@example.com',
        ])->assertCreated();
        $this->withToken($ownerToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/revoke')
            ->assertOk();
        $activeToken = $this->login($activeInvitee, 'revoked-password')->json('data.access_token');
        $this->withToken($activeToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/accept', [
            'token' => $issue->json('data.token'),
        ])->assertNotFound();

        $validInvitee = $this->identity('valid@example.com', 'valid-password');
        $issue = $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'valid@example.com',
        ])->assertCreated();
        $validToken = $this->login($validInvitee, 'valid-password')->json('data.access_token');
        $this->withToken($validToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/accept', [
            'token' => $issue->json('data.token'),
        ])->assertOk();
        $this->withToken($validToken)->postJson('/api/v1/invitations/'.$issue->json('data.invitation_id').'/accept', [
            'token' => $issue->json('data.token'),
        ])->assertNotFound();
    }

    public function test_revoking_a_membership_removes_it_from_the_member_listing_immediately(): void
    {
        [$school, $owner] = $this->schoolWithOwner();
        $member = $this->identity('revoke@example.com', 'revoke-password');
        $membership = SchoolMembership::create([
            'school_id' => $school->id,
            'user_id' => $member->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(RevokeSchoolMembershipAction::class)->handle($owner, $membership->public_id);

        $memberToken = $this->login($member, 'revoke-password')->json('data.access_token');
        $this->withToken($memberToken)->getJson('/api/v1/me/memberships')
            ->assertOk()
            ->assertJsonCount(0, 'data.memberships');
        self::assertSame('revoked', $membership->fresh()->status);
    }

    public function test_duplicate_invitation_and_unverified_contacts_are_rejected(): void
    {
        [$school, $owner] = $this->schoolWithOwner();
        $unverified = UserIdentity::factory()->withPassword('unverified-password')->create();
        $unverified->contacts()->update(['canonical_value' => 'unverified@example.com']);
        $ownerToken = $this->login($owner, 'owner-password')->json('data.access_token');

        $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'unverified@example.com',
        ])->assertUnprocessable();

        $invitee = $this->identity('duplicate@example.com', 'duplicate-password');
        $first = $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'duplicate@example.com',
        ])->assertCreated();
        $this->withToken($ownerToken)->postJson('/api/v1/schools/'.$school->public_id.'/invitations', [
            'contact' => 'duplicate@example.com',
        ])->assertUnprocessable();

        self::assertSame($invitee->id, SchoolInvitation::query()->firstOrFail()->invitee_id);
        self::assertNotSame($first->json('data.token'), SchoolInvitation::query()->firstOrFail()->getRawOriginal('token_hash'));
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithOwner(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Academy']);
        $owner = $this->identity('owner@example.com', 'owner-password');
        SchoolMembership::create([
            'school_id' => $school->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'is_owner' => true,
            'joined_at' => now(),
        ]);

        return [$school, $owner];
    }

    private function identity(string $email, string $password): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update([
            'canonical_value' => $email,
            'verified_at' => now(),
        ]);

        return $identity;
    }

    /** @return TestResponse<JsonResponse> */
    private function login(UserIdentity $identity, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ])->assertOk();
    }
}
