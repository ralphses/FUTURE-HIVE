<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StaffProfileTest extends TestCase
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

    public function test_staff_profile_requires_an_existing_membership_and_tracks_employment_state(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'staff-admin@example.com');
        $member = UserIdentity::factory()->withPassword('staff-member-password')->create();
        $member->contacts()->update(['canonical_value' => 'staff-member@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $member->id]);
        $token = $this->loginAndSelect($admin, $school);
        $base = '/api/v1/schools/'.$school->public_id.'/staff';

        $staff = $this->withToken($token)->postJson($base, [
            'membership_id' => $membership->public_id,
            'staff_number' => 'STAFF-001',
            'legal_name' => 'Fictional Staff Member',
            'job_title' => 'Teacher',
            'department' => 'Academic',
            'employment_status' => 'active',
            'school_id' => 999,
        ])->assertCreated()->assertJsonPath('data.employment_status', 'pending')->json('data.id');

        $this->withToken($token)->postJson($base.'/'.$staff.'/activate')->assertOk()->assertJsonPath('data.employment_status', 'active');
        $this->withToken($token)->postJson($base.'/'.$staff.'/suspend', ['reason' => 'Fictional staffing review'])->assertOk()->assertJsonPath('data.employment_status', 'suspended');
        $this->withToken($token)->postJson($base.'/'.$staff.'/end', ['reason' => 'Fictional employment closure'])->assertOk()->assertJsonPath('data.employment_status', 'ended');
        $this->withToken($token)->patchJson($base.'/'.$staff, ['staff_number' => 'STAFF-002', 'legal_name' => 'Changed Name'])->assertUnprocessable();

        self::assertSame(1, StaffProfile::query()->count());
        self::assertSame('ended', StaffProfile::query()->firstOrFail()->employment_status);
    }

    public function test_staff_profiles_are_school_local_and_management_is_permission_gated(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'staff-scope-admin@example.com');
        [$otherSchool, $otherAdmin] = $this->schoolWithRole('school_admin', 'staff-other-admin@example.com');
        $member = UserIdentity::factory()->withPassword('staff-scope-member-password')->create();
        $member->contacts()->update(['canonical_value' => 'staff-scope-member@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $member->id]);
        $adminToken = $this->loginAndSelect($admin, $school);
        $otherToken = $this->loginAndSelect($otherAdmin, $otherSchool);
        $path = '/api/v1/schools/'.$school->public_id.'/staff';

        $this->withToken($adminToken)->postJson($path, ['membership_id' => $membership->public_id, 'staff_number' => 'STAFF-LOCAL', 'legal_name' => 'Local Staff'])->assertCreated();
        $this->withToken($otherToken)->getJson($path)->assertNotFound();
        $this->withToken($adminToken)->postJson($path, ['membership_id' => $membership->public_id, 'staff_number' => 'STAFF-LOCAL', 'legal_name' => 'Duplicate Staff'])->assertUnprocessable();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $role, string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Staff School']);
        $identity = UserIdentity::factory()->withPassword('staff-admin-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'staff-admin-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
