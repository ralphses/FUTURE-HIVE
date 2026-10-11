<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeacherWorklistTest extends TestCase
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

    public function test_eligible_teacher_can_read_an_empty_worklist_without_fabricated_actions(): void
    {
        $school = School::factory()->create();
        $teacher = UserIdentity::factory()->withPassword('teacher-password')->create();
        $teacher->contacts()->update(['canonical_value' => 'teacher-worklist@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->getKey(), 'user_id' => $teacher->getKey(), 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->getKey(), 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $teacher->getKey(), 'assigned_at' => now()]);
        TenantContext::runInternal(TenantContext::fromMembership($membership), 'test teacher worklist setup', fn (): StaffProfile => StaffProfile::create(['school_id' => $school->getKey(), 'school_membership_id' => $membership->getKey(), 'staff_number' => 'STAFF-WORKLIST', 'legal_name' => 'Fictional Teacher', 'job_title' => 'Teacher', 'employment_status' => 'active']));

        $token = $this->postJson('/api/v1/auth/login', ['login' => 'teacher-worklist@example.com', 'password' => 'teacher-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/teacher-worklist')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_non_teacher_staff_cannot_read_the_worklist(): void
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('staff-password')->create();
        $identity->contacts()->update(['canonical_value' => 'staff-worklist@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->getKey(), 'user_id' => $identity->getKey(), 'status' => 'active', 'joined_at' => now()]);
        TenantContext::runInternal(TenantContext::fromMembership($membership), 'test non-teacher worklist setup', fn (): StaffProfile => StaffProfile::create(['school_id' => $school->getKey(), 'school_membership_id' => $membership->getKey(), 'staff_number' => 'STAFF-NONTEACHER', 'legal_name' => 'Fictional Staff', 'job_title' => 'Office Staff', 'employment_status' => 'active']));

        $token = $this->postJson('/api/v1/auth/login', ['login' => 'staff-worklist@example.com', 'password' => 'staff-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/teacher-worklist')->assertNotFound();
    }
}
