<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SchoolRoleAuthorizationTest extends TestCase
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
        config(['auth.jwt.private_key' => $this->privateKey, 'auth.jwt.public_keys' => ['test-key' => $this->publicKey], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api']);
    }

    public function test_catalogue_and_effective_permissions_are_deterministic_for_an_active_member(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'admin@example.com');
        $token = $this->login($admin, 'admin-password', $school)->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/roles')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'bursar')
            ->assertJsonPath('data.8.key', 'teacher');
        $this->withToken($token)->getJson('/api/v1/me/schools/'.$school->public_id.'/permissions')
            ->assertOk()
            ->assertJsonPath('data.permissions.0', 'academic.assessment-components.manage')
            ->assertJsonFragment(['guardian.links.read']);

        self::assertSame(12, Role::query()->count());
        self::assertSame(57, Permission::query()->count());
    }

    public function test_admin_can_assign_multiple_roles_and_revoke_one_with_audit_history(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'admin@example.com');
        $member = $this->identity('member@example.com', 'member-password');
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $member->id, 'status' => 'active', 'joined_at' => now()]);
        $adminToken = $this->login($admin, 'admin-password', $school)->json('data.access_token');

        $assigned = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/memberships/'.$membership->public_id.'/roles', ['roles' => ['teacher', 'counsellor', 'school_admin'], 'school_id' => 999, 'is_owner' => true]);
        $assigned->assertCreated()->assertJsonCount(3, 'data.assignments');
        self::assertSame(3, MembershipRole::query()->where('school_membership_id', $membership->id)->count());
        self::assertSame(3, AuditEvent::query()->where('action', 'school.role_assigned')->count());

        $assignment = MembershipRole::query()->where('school_membership_id', $membership->id)->whereHas('role', fn ($query) => $query->where('key', 'counsellor'))->firstOrFail();
        $this->withToken($adminToken)->deleteJson('/api/v1/schools/'.$school->public_id.'/memberships/'.$membership->public_id.'/roles/'.$assignment->public_id)
            ->assertOk()->assertJsonPath('data.revoked', true);
        self::assertNotNull($assignment->fresh()->revoked_at);
        self::assertSame(1, AuditEvent::query()->where('action', 'school.role_revoked')->count());
    }

    public function test_unprivileged_and_cross_school_role_changes_are_denied(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'admin@example.com');
        $member = $this->identity('member@example.com', 'member-password');
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $member->id, 'status' => 'active', 'joined_at' => now()]);
        $memberToken = $this->login($member, 'member-password', $school)->json('data.access_token');
        $this->withToken($memberToken)->postJson('/api/v1/schools/'.$school->public_id.'/memberships/'.$membership->public_id.'/roles', ['roles' => ['teacher']])->assertNotFound();

        $otherSchool = School::factory()->create(['name' => 'Other Fictional School']);
        $this->withToken($this->login($admin, 'admin-password', $school)->json('data.access_token'))
            ->postJson('/api/v1/schools/'.$otherSchool->public_id.'/memberships/'.$membership->public_id.'/roles', ['roles' => ['teacher']])
            ->assertNotFound();
    }

    public function test_revoked_membership_has_no_effective_permissions(): void
    {
        [$school, $member] = $this->schoolWithRole('teacher', 'teacher@example.com');
        $membership = SchoolMembership::query()->where('user_id', $member->id)->firstOrFail();
        $token = $this->login($member, 'member-password', $school)->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/me/schools/'.$school->public_id.'/permissions')->assertOk()->assertJsonPath('data.permissions', ['academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.assignments.manage', 'academic.assignments.read', 'academic.class-arms.read', 'academic.grading-scales.read', 'academic.offerings.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'academic.records.read', 'academic.sessions.read', 'academic.structure.read', 'academic.subjects.read', 'school.memberships.list', 'students.documents.read', 'students.enrollments.read', 'students.profile.read', 'students.read']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'test']);
        $this->withToken($token)->getJson('/api/v1/me/schools/'.$school->public_id.'/permissions')->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Academy']);
        $identity = $this->identity($email, $roleKey === 'teacher' ? 'member-password' : 'admin-password');
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'is_owner' => $roleKey === 'school_admin', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function identity(string $email, string $password): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);

        return $identity;
    }

    /** @return TestResponse<JsonResponse> */
    private function login(UserIdentity $identity, string $password, ?School $school = null): TestResponse
    {
        $response = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk();

        if ($school instanceof School) {
            $this->withToken($response->json('data.access_token'))
                ->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])
                ->assertOk();
        }

        return $response;
    }
}
