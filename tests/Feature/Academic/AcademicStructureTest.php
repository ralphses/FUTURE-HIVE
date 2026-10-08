<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicStructureTest extends TestCase
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

    public function test_admin_can_create_levels_and_sections_for_flexible_school_stages(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'structure-admin-password');
        $token = $this->loginAndSelect($identity, $school, 'structure-admin-password');
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Primary 1', 'code' => 'PRI1', 'sequence' => 1, 'stage' => 'primary', 'status' => 'inactive', 'school_id' => 999])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Blue', 'code' => 'BLUE', 'sequence' => 1, 'status' => 'inactive'])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/academic-levels')->assertOk()->assertJsonPath('data.items.0.id', $level);
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections')->assertOk()->assertJsonPath('data.items.0.id', $section);
        self::assertSame(1, AcademicLevel::query()->where('school_id', $school->id)->count());
        self::assertSame(1, AcademicSection::query()->where('school_id', $school->id)->count());
    }

    public function test_duplicate_structure_names_codes_and_sequences_are_rejected_in_school_scope(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'structure-duplicate-password');
        $token = $this->loginAndSelect($identity, $school, 'structure-duplicate-password');
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Primary 1', 'code' => 'PRI1', 'sequence' => 1])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Other', 'code' => 'PRI1', 'sequence' => 2])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Blue', 'code' => 'BLUE', 'sequence' => 1])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Blue', 'code' => 'GREEN', 'sequence' => 2])->assertUnprocessable();
    }

    public function test_same_structure_values_are_allowed_in_different_schools(): void
    {
        [$schoolA, $identityA] = $this->schoolWithRole('school_admin', 'structure-school-a-password');
        [$schoolB, $identityB] = $this->schoolWithRole('school_admin', 'structure-school-b-password');
        $tokenA = $this->loginAndSelect($identityA, $schoolA, 'structure-school-a-password');
        $tokenB = $this->loginAndSelect($identityB, $schoolB, 'structure-school-b-password');
        $this->withToken($tokenA)->postJson('/api/v1/schools/'.$schoolA->public_id.'/academic-levels', ['name' => 'Primary 1', 'code' => 'PRI1', 'sequence' => 1])->assertCreated();
        $this->withToken($tokenB)->postJson('/api/v1/schools/'.$schoolB->public_id.'/academic-levels', ['name' => 'Primary 1', 'code' => 'PRI1', 'sequence' => 1])->assertCreated();
        $this->withToken($tokenA)->getJson('/api/v1/schools/'.$schoolB->public_id.'/academic-levels')->assertNotFound();
    }

    public function test_inactive_levels_cannot_receive_sections_and_transitions_are_idempotent(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'structure-state-password');
        $token = $this->loginAndSelect($identity, $school, 'structure-state-password');
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Secondary 1', 'code' => 'SEC1', 'sequence' => 1])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Red', 'code' => 'RED', 'sequence' => 1])->assertUnprocessable();
        self::assertSame(2, AuditEvent::query()->where('subject_type', 'academic_structure')->count());
    }

    public function test_read_and_manage_permissions_and_parent_selectors_are_enforced(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'structure-permission-admin-password');
        $adminToken = $this->loginAndSelect($admin, $school, 'structure-permission-admin-password');
        $level = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Nursery 1', 'code' => 'NUR1', 'sequence' => 1, 'stage' => 'nursery'])->assertCreated()->json('data.id');
        [$otherSchool, $teacher] = $this->schoolWithRole('teacher', 'structure-permission-teacher-password');
        $teacherToken = $this->loginAndSelect($teacher, $otherSchool, 'structure-permission-teacher-password');
        $this->withToken($teacherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/academic-levels')->assertOk();
        $this->withToken($teacherToken)->postJson('/api/v1/schools/'.$otherSchool->public_id.'/academic-levels', ['name' => 'Blocked', 'code' => 'BLOCK', 'sequence' => 1])->assertNotFound();
        $this->withToken($adminToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/academic-levels')->assertNotFound();
        $this->withToken($adminToken)->getJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/not-the-child')->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Structure School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
