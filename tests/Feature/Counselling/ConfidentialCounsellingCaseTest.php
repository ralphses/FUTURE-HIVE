<?php

declare(strict_types=1);

namespace Tests\Feature\Counselling;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Registry\Domain\Models\CounsellingCase;
use App\Contexts\Registry\Domain\Models\CounsellingCaseNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConfidentialCounsellingCaseTest extends TestCase
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

    public function test_assigned_counsellor_can_create_case_and_immutable_private_notes(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'case-admin@example.com', 'case-admin-password');
        $counsellor = $this->memberInSchool($school, 'case-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'case-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school);
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $token = $this->loginAndSelect($counsellor, $school, 'case-counsellor-password');
        $base = '/api/v1/schools/'.$school->public_id.'/counselling-cases';

        $case = $this->withToken($token)->postJson($base, ['assignment_id' => $assignment, 'title' => 'Fictional support case', 'category' => 'wellbeing', 'status' => 'closed', 'school_id' => 999])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
        $note = $this->withToken($token)->postJson($base.'/'.$case.'/notes', ['note_type' => 'session', 'content' => 'Fictional confidential note'])->assertCreated()->assertJsonPath('data.content', 'Fictional confidential note')->json('data.id');
        $this->withToken($token)->getJson($base.'/'.$case)->assertOk()->assertJsonMissing(['content' => 'Fictional confidential note']);
        $this->withToken($token)->getJson($base.'/'.$case.'/notes/'.$note)->assertOk()->assertJsonPath('data.content', 'Fictional confidential note');
        self::assertSame(1, CounsellingCase::query()->count());
        self::assertSame(1, CounsellingCaseNote::query()->count());
        self::assertSame(2, AuditEvent::query()->whereIn('subject_type', ['counselling_case', 'counselling_case_note'])->count());
        $this->withToken($token)->postJson($base.'/'.$case.'/close', ['reason' => 'Fictional closure'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->withToken($token)->postJson($base.'/'.$case.'/close', ['reason' => 'Repeated fictional closure'])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_case_metadata_does_not_grant_note_access_and_revocation_blocks_counsellor(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'case-scope-admin@example.com', 'case-scope-admin-password');
        $counsellor = $this->memberInSchool($school, 'case-scope-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'case-scope-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school, 'CNS-SCOPE-001');
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'case-scope-counsellor-password');
        $base = '/api/v1/schools/'.$school->public_id.'/counselling-cases';
        $case = $this->withToken($counsellorToken)->postJson($base, ['assignment_id' => $assignment, 'title' => 'Fictional scope case', 'category' => 'support'])->assertCreated()->json('data.id');
        $this->withToken($adminToken)->getJson($base.'/'.$case)->assertOk();
        $this->withToken($adminToken)->getJson($base.'/'.$case.'/notes')->assertNotFound();
        $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments/'.$assignment.'/revoke', ['reason' => 'Fictional reassignment'])->assertOk();
        $this->withToken($counsellorToken)->getJson($base.'/'.$case)->assertNotFound();
        $this->withToken($counsellorToken)->getJson($base.'/'.$case.'/notes')->assertNotFound();
    }

    private function createStaff(string $token, School $school, UserIdentity $identity): string
    {
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $identity->id)->firstOrFail();
        $staff = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff', ['membership_id' => $membership->public_id, 'staff_number' => 'CNS-TEST-001', 'legal_name' => 'Fictional Counsellor'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff/'.$staff.'/activate')->assertOk();

        return $staff;
    }

    private function createEnrolledStudent(string $token, School $school, string $number = 'CNS-TEST-STUDENT'): string
    {
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => $number, 'display_name' => 'Fictional Counselling Student'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/activate')->assertOk();
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Counselling Session '.$number, 'code' => 'CNS'.substr($number, -3), 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Counselling Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Counselling Level '.$number, 'code' => 'CL'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Counselling Section', 'code' => 'CS'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $arm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Counselling Arm', 'code' => 'CA'.substr($number, -3), 'capacity' => 30])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/enrollments', ['session_id' => $session, 'term_id' => $term, 'level_id' => $level, 'section_id' => $section, 'class_arm_id' => $arm, 'start_date' => '2025-09-10'])->assertCreated();

        return $student;
    }

    /** @return array{0:School,1:UserIdentity} */
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
