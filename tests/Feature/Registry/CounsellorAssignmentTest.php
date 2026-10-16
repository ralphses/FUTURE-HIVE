<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Registry\Domain\Models\CounsellorAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CounsellorAssignmentTest extends TestCase
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

    public function test_leadership_can_assign_and_revoke_a_counsellor_idempotently(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'counselling-admin@example.com', 'counselling-admin-password');
        $counsellor = $this->memberInSchool($school, 'counsellor@example.com', 'counsellor');
        $token = $this->loginAndSelect($admin, $school, 'counselling-admin-password');
        $staff = $this->createStaff($token, $school, $counsellor, 'CNS-001');
        $student = $this->createEnrolledStudent($token, $school);
        $base = '/api/v1/schools/'.$school->public_id.'/counselling-assignments';

        $assignment = $this->withToken($token)->postJson($base, [
            'staff_id' => $staff,
            'student_id' => $student,
            'reason' => 'Fictional wellbeing support assignment',
            'status' => 'revoked',
            'school_id' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        $this->withToken($token)->postJson($base, ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->assertJsonPath('data.id', $assignment);
        self::assertSame(1, CounsellorAssignment::query()->count());
        self::assertSame(1, AuditEvent::query()->where('subject_type', 'counsellor_assignment')->count());

        $this->withToken($token)->postJson($base.'/'.$assignment.'/revoke', ['reason' => 'Fictional reassignment'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withToken($token)->postJson($base.'/'.$assignment.'/revoke', ['reason' => 'Repeated fictional reassignment'])->assertOk()->assertJsonPath('data.status', 'revoked');
        self::assertSame(1, CounsellorAssignment::query()->where('status', 'revoked')->count());
    }

    public function test_counsellor_reads_only_own_assignment_metadata(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'counselling-principal@example.com', 'counselling-principal-password');
        $firstCounsellor = $this->memberInSchool($school, 'first-counsellor@example.com', 'counsellor');
        $secondCounsellor = $this->memberInSchool($school, 'second-counsellor@example.com', 'counsellor');
        $token = $this->loginAndSelect($admin, $school, 'counselling-principal-password');
        $firstStaff = $this->createStaff($token, $school, $firstCounsellor, 'CNS-002');
        $secondStaff = $this->createStaff($token, $school, $secondCounsellor, 'CNS-003');
        $student = $this->createEnrolledStudent($token, $school, 'CNS-STUDENT-002');
        $base = '/api/v1/schools/'.$school->public_id.'/counselling-assignments';

        $this->withToken($token)->postJson($base, ['staff_id' => $firstStaff, 'student_id' => $student])->assertCreated();
        $this->withToken($token)->postJson($base, ['staff_id' => $secondStaff, 'student_id' => $student])->assertCreated();

        $firstToken = $this->loginAndSelect($firstCounsellor, $school, 'first-counsellor-password');
        $this->withToken($firstToken)->getJson($base)->assertOk()->assertJsonCount(1, 'data.items');
        $secondToken = $this->loginAndSelect($secondCounsellor, $school, 'second-counsellor-password');
        $this->withToken($secondToken)->getJson($base)->assertOk()->assertJsonCount(1, 'data.items');
        $this->withToken($firstToken)->postJson($base, ['staff_id' => $secondStaff, 'student_id' => $student])->assertNotFound();
    }

    public function test_inactive_counsellors_and_cross_school_context_are_denied(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'counselling-scope@example.com', 'counselling-scope-password');
        [$otherSchool, $otherAdmin] = $this->schoolWithRole('school_admin', 'counselling-other@example.com', 'counselling-other-password');
        $member = $this->memberInSchool($school, 'unassigned-counsellor@example.com', null);
        $token = $this->loginAndSelect($admin, $school, 'counselling-scope-password');
        $staff = $this->createStaff($token, $school, $member, 'CNS-004');
        $student = $this->createEnrolledStudent($token, $school, 'CNS-STUDENT-004');
        $base = '/api/v1/schools/'.$school->public_id.'/counselling-assignments';

        $this->withToken($token)->postJson($base, ['staff_id' => $staff, 'student_id' => $student])->assertNotFound();
        $otherToken = $this->loginAndSelect($otherAdmin, $otherSchool, 'counselling-other-password');
        $this->withToken($otherToken)->getJson($base)->assertNotFound();
    }

    private function createStaff(string $token, School $school, UserIdentity $identity, string $number): string
    {
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $identity->id)->firstOrFail();
        $staff = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff', ['membership_id' => $membership->public_id, 'staff_number' => $number, 'legal_name' => 'Fictional Counsellor'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff/'.$staff.'/activate')->assertOk();

        return $staff;
    }

    private function createEnrolledStudent(string $token, School $school, string $number = 'CNS-STUDENT-001'): string
    {
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => $number, 'display_name' => 'Fictional Counselling Student'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/activate')->assertOk();
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Counselling Session '.$number, 'code' => 'CNS'.substr($number, -3), 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional session activation'])->assertOk();
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Counselling Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional term activation'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Counselling Level '.$number, 'code' => 'CL'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Counselling Section', 'code' => 'CS'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Counselling Arm', 'code' => 'CA'.substr($number, -3), 'capacity' => 30])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/enrollments', ['session_id' => $session, 'term_id' => $term, 'level_id' => $level, 'section_id' => $section, 'class_arm_id' => $classArm, 'start_date' => '2025-09-10'])->assertCreated();

        return $student;
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $role, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Counselling School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, $role);

        return [$school, $identity];
    }

    private function memberInSchool(School $school, string $email, ?string $role): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword(str_replace('@example.com', '-password', $email))->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, $role);

        return $identity;
    }

    private function assignRole(School $school, UserIdentity $identity, ?string $role): void
    {
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        if ($role !== null) {
            MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        }
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
