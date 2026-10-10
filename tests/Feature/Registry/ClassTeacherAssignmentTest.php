<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Registry\Domain\Models\ClassTeacherAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClassTeacherAssignmentTest extends TestCase
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

    public function test_assigns_an_active_teacher_staff_profile_to_a_class_arm(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'class-teacher-admin@example.com', 'class-teacher-admin-password');
        $teacher = $this->memberInSchool($school, 'class-teacher@example.com', 'teacher');
        $token = $this->loginAndSelect($admin, $school, 'class-teacher-admin-password');
        $staff = $this->createStaff($token, $school, $teacher, 'CT-001');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term'], $configuration['class_arm']);

        $assignment = $this->withToken($token)->postJson($path, [
            'staff_id' => $staff,
            'effective_start' => '2025-09-01',
            'effective_end' => '2025-12-15',
            'reason' => 'Fictional class leadership plan',
            'status' => 'revoked',
            'school_id' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        self::assertTrue(ClassTeacherAssignment::query()->where('public_id', $assignment)->exists());
        self::assertSame(1, AuditEvent::query()->where('subject_type', 'class_teacher_assignment')->count());
    }

    public function test_class_arm_and_teacher_overlap_rules_are_enforced_and_revoke_is_idempotent(): void
    {
        [$school, $admin] = $this->schoolWithRole('proprietor', 'class-teacher-conflict-admin@example.com', 'class-teacher-conflict-admin-password');
        $teacher = $this->memberInSchool($school, 'class-teacher-conflict@example.com', 'teacher');
        $token = $this->loginAndSelect($admin, $school, 'class-teacher-conflict-admin-password');
        $staff = $this->createStaff($token, $school, $teacher, 'CT-002');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term'], $configuration['class_arm']);

        $assignment = $this->withToken($token)->postJson($path, ['staff_id' => $staff, 'effective_start' => '2025-09-01'])->assertCreated()->json('data.id');
        self::assertSame(1, ClassTeacherAssignment::query()->count());
        self::assertSame('active', ClassTeacherAssignment::query()->firstOrFail()->status);
        $this->withToken($token)->postJson($path, ['staff_id' => $staff, 'effective_start' => '2025-09-01'])->assertCreated()->assertJsonPath('data.id', $assignment);
        $this->withToken($token)->patchJson($path.'/'.$assignment, ['effective_start' => '2025-08-01'])->assertUnprocessable();
        $revokePath = $path.'/'.$assignment.'/revoke';
        $this->withToken($token)->postJson($revokePath, ['reason' => 'Fictional reassignment'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withToken($token)->postJson($revokePath, ['reason' => 'Repeated fictional reassignment'])->assertOk()->assertJsonPath('data.status', 'revoked');
    }

    public function test_non_teacher_and_non_manager_cannot_assign_class_teachers(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'class-teacher-auth-admin@example.com', 'class-teacher-auth-admin-password');
        $member = $this->memberInSchool($school, 'class-teacher-member@example.com', null);
        $token = $this->loginAndSelect($admin, $school, 'class-teacher-auth-admin-password');
        $staff = $this->createStaff($token, $school, $member, 'CT-003');
        $configuration = $this->configuration($school, $token);
        $path = $this->path($school, $configuration['session'], $configuration['term'], $configuration['class_arm']);

        $this->withToken($token)->postJson($path, ['staff_id' => $staff, 'effective_start' => '2025-09-01'])->assertNotFound();
        $memberToken = $this->loginAndSelect($member, $school, 'class-teacher-member-password');
        $this->withToken($memberToken)->postJson($path, ['staff_id' => $staff, 'effective_start' => '2025-09-01'])->assertNotFound();
    }

    /** @return array{session: string, term: string, class_arm: string} */
    private function configuration(School $school, string $token): array
    {
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Class Teacher Session', 'code' => 'CTS', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Class Teacher Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Class Teacher Level', 'code' => 'CTL', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Class Teacher Section', 'code' => 'CTS', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Class Teacher Arm', 'code' => 'CTA', 'capacity' => 30])->assertCreated()->json('data.id');

        return ['session' => $session, 'term' => $term, 'class_arm' => $classArm];
    }

    private function createStaff(string $token, School $school, UserIdentity $identity, string $number): string
    {
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $identity->id)->firstOrFail();

        $staff = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff', ['membership_id' => $membership->public_id, 'staff_number' => $number, 'legal_name' => 'Fictional Teacher'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff/'.$staff.'/activate')->assertOk();

        return $staff;
    }

    private function path(School $school, string $session, string $term, string $classArm): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/class-arms/'.$classArm.'/class-teacher-assignments';
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Class Teacher School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, $roleKey);

        return [$school, $identity];
    }

    private function memberInSchool(School $school, string $email, ?string $roleKey): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword(str_replace('@example.com', '-password', $email))->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, $roleKey);

        return $identity;
    }

    private function assignRole(School $school, UserIdentity $identity, ?string $roleKey): void
    {
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        if ($roleKey !== null) {
            MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        }
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
