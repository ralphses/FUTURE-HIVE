<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicClassArmTest extends TestCase
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

    public function test_admin_can_create_and_update_a_class_arm_with_bounded_capacity(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'class-arm-admin-password');
        $token = $this->loginAndSelect($identity, $school, 'class-arm-admin-password');
        [$level, $section] = $this->structure($school, $token);

        $classArm = $this->withToken($token)->postJson($this->path($school, $level, $section), ['name' => 'Blue Arm', 'code' => 'BLUE', 'capacity' => 30, 'status' => 'inactive', 'school_id' => 999])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.capacity', 30)->json('data.id');
        $this->withToken($token)->patchJson($this->path($school, $level, $section, $classArm), ['name' => 'Blue Arm', 'code' => 'BLUE-2', 'capacity' => 45, 'teacher_id' => 999])->assertOk()->assertJsonPath('data.capacity', 45);

        self::assertSame(1, AcademicClassArm::query()->where('school_id', $school->id)->count());
        self::assertSame(2, AuditEvent::query()->where('subject_type', 'academic_class_arm')->count());
    }

    public function test_duplicate_values_are_rejected_only_within_the_parent_scope(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'class-arm-duplicate-password');
        $token = $this->loginAndSelect($identity, $school, 'class-arm-duplicate-password');
        [$level, $section] = $this->structure($school, $token);
        $path = $this->path($school, $level, $section);
        $this->withToken($token)->postJson($path, ['name' => 'Blue Arm', 'code' => 'BLUE', 'capacity' => 30])->assertCreated();
        $this->withToken($token)->postJson($path, ['name' => 'Blue Arm', 'code' => 'GREEN', 'capacity' => 30])->assertUnprocessable();
        $this->withToken($token)->postJson($path, ['name' => 'Green Arm', 'code' => 'BLUE', 'capacity' => 30])->assertUnprocessable();

        $secondSection = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Red', 'code' => 'RED', 'sequence' => 2])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($this->path($school, $level, $secondSection), ['name' => 'Blue Arm', 'code' => 'BLUE', 'capacity' => 30])->assertCreated();
    }

    public function test_inactive_parents_and_invalid_capacity_are_rejected(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'class-arm-state-password');
        $token = $this->loginAndSelect($identity, $school, 'class-arm-state-password');
        [$level, $section] = $this->structure($school, $token);
        $this->withToken($token)->postJson($this->path($school, $level, $section), ['name' => 'Invalid', 'capacity' => 0])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/deactivate')->assertOk();
        $this->withToken($token)->postJson($this->path($school, $level, $section), ['name' => 'Blocked', 'capacity' => 30])->assertUnprocessable();
    }

    public function test_cross_school_and_mismatched_parent_selectors_are_hidden(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'class-arm-cross-password');
        [$otherSchool, $otherIdentity] = $this->schoolWithRole('school_admin', 'class-arm-other-password');
        $token = $this->loginAndSelect($identity, $school, 'class-arm-cross-password');
        $otherToken = $this->loginAndSelect($otherIdentity, $otherSchool, 'class-arm-other-password');
        [$level, $section] = $this->structure($school, $token);
        [$otherLevel, $otherSection] = $this->structure($otherSchool, $otherToken);

        $this->withToken($token)->getJson($this->path($school, $otherLevel, $otherSection))->assertNotFound();
        $this->withToken($token)->getJson($this->path($school, $level, $otherSection))->assertNotFound();
    }

    public function test_read_permission_is_separate_from_manage_permission(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'class-arm-permission-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'class-arm-permission-admin-password');
        [$level, $section] = $this->structure($school, $adminToken);
        $this->withToken($adminToken)->postJson($this->path($school, $level, $section), ['name' => 'Green Arm', 'capacity' => 30])->assertCreated();
        $reader = UserIdentity::factory()->withPassword('class-arm-reader-password')->create();
        $readerMembership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $reader->id]);
        MembershipRole::create(['school_membership_id' => $readerMembership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $readerToken = $this->loginAndSelect($reader, $school, 'class-arm-reader-password');
        $this->withToken($readerToken)->getJson($this->path($school, $level, $section))->assertOk();
        $this->withToken($readerToken)->postJson($this->path($school, $level, $section), ['name' => 'Blocked Arm', 'capacity' => 30])->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Class Arm School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    /** @return array{0: string, 1: string} */
    private function structure(School $school, string $token): array
    {
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Primary 1', 'code' => 'PRI1', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Blue', 'code' => 'BLUE', 'sequence' => 1])->assertCreated()->json('data.id');

        return [$level, $section];
    }

    private function path(School $school, string $level, string $section, ?string $suffix = null): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms'.($suffix === null ? '' : '/'.$suffix);
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
