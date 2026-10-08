<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchoolLifecycleTest extends TestCase
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
        config([
            'auth.jwt.private_key' => $this->privateKey,
            'auth.jwt.public_keys' => ['test-key' => $this->publicKey],
            'auth.jwt.current_kid' => 'test-key',
            'auth.jwt.issuer' => 'https://schoolos.test',
            'auth.jwt.audience' => 'schoolos-api',
        ]);
    }

    public function test_authorized_actor_can_suspend_and_reactivate_a_school(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'lifecycle-password');
        $token = $this->loginAndSelect($identity, $school, 'lifecycle-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/lifecycle')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/suspend', [
            'reason' => 'Temporary operational pause',
        ])->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.transition.from', 'active')
            ->assertJsonPath('data.transition.to', 'suspended');

        $this->assertDatabaseHas('schools', ['id' => $school->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('audit_events', [
            'school_id' => $school->id,
            'action' => 'school.lifecycle_changed',
            'reason' => 'Temporary operational pause',
        ]);

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/suspend', [
            'reason' => 'Repeated pause request',
        ])->assertOk()
            ->assertJsonPath('data.transition.changed', false)
            ->assertJsonPath('data.status', 'suspended');

        self::assertSame(1, AuditEvent::query()->where('action', 'school.lifecycle_changed')->count());

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/lifecycle')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/reactivate', [
            'reason' => 'Operational pause ended',
        ])->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('schools', ['id' => $school->id, 'status' => 'active']);
        self::assertSame(2, AuditEvent::query()->where('action', 'school.lifecycle_changed')->count());
    }

    public function test_suspension_blocks_ordinary_school_writes_but_lifecycle_reactivation_remains_available(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'suspension-password');
        $token = $this->loginAndSelect($identity, $school, 'suspension-password');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/suspend', [
            'reason' => 'Writes must pause',
        ])->assertOk();

        $this->withToken($token)->putJson('/api/v1/schools/'.$school->public_id.'/profile', [
            'city' => 'Blocked City',
        ])->assertNotFound();

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/reactivate', [
            'reason' => 'Writes may resume',
        ])->assertOk();

        $this->withToken($token)->putJson('/api/v1/schools/'.$school->public_id.'/profile', [
            'city' => 'Active City',
        ])->assertOk();
    }

    public function test_unauthorized_and_cross_school_lifecycle_requests_are_denied_generically(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'admin-lifecycle-password');
        [$otherSchool, $teacher] = $this->schoolWithRole('teacher', 'teacher-lifecycle-password');
        $teacherToken = $this->loginAndSelect($teacher, $otherSchool, 'teacher-lifecycle-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'admin-lifecycle-password');

        $this->withToken($teacherToken)->postJson('/api/v1/schools/'.$otherSchool->public_id.'/lifecycle/suspend', [
            'reason' => 'Not allowed',
        ])->assertNotFound();

        $this->withToken($adminToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/lifecycle')->assertNotFound();

        SchoolMembership::query()->where('user_id', $admin->id)->where('school_id', $school->id)->update(['status' => 'revoked']);
        $this->withToken($adminToken)->getJson('/api/v1/schools/'.$school->public_id.'/lifecycle')->assertNotFound();
    }

    public function test_archive_is_terminal_and_preserves_school_records(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'archive-password');
        $token = $this->loginAndSelect($identity, $school, 'archive-password');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/archive', [
            'reason' => 'School permanently closed',
            'confirmation' => 'ARCHIVE',
            'status' => 'active',
            'school_id' => 'forged',
            'updated_at' => '2030-01-01T00:00:00Z',
        ])->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('schools', ['id' => $school->id, 'status' => 'archived']);
        $this->assertDatabaseHas('school_profiles', ['school_id' => $school->id]);
        $this->assertDatabaseHas('school_memberships', ['school_id' => $school->id, 'user_id' => $identity->id]);

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/lifecycle')->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertNotFound();
    }

    public function test_lifecycle_requests_validate_reason_and_archive_confirmation(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'validation-password');
        $token = $this->loginAndSelect($identity, $school, 'validation-password');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/suspend', [])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/lifecycle/archive', [
            'reason' => 'Archive attempt',
            'confirmation' => 'archive',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('schools', ['id' => $school->id, 'status' => 'active']);
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Lifecycle Academy']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create([
            'school_membership_id' => $membership->id,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
            'assigned_by' => $identity->id,
            'assigned_at' => now(),
        ]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
