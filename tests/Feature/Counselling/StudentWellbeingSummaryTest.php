<?php

declare(strict_types=1);

namespace Tests\Feature\Counselling;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentWellbeingSummaryTest extends TestCase
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

    public function test_assigned_counsellor_reads_safe_summary_without_private_notes(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'wellbeing-admin@example.com', 'wellbeing-admin-password');
        $counsellor = $this->memberInSchool($school, 'wellbeing-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'wellbeing-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school);
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'wellbeing-counsellor-password');
        $case = $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-cases', ['assignment_id' => $assignment, 'title' => 'Private case title', 'category' => 'support'])->assertCreated()->json('data.id');
        $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/development-plans', ['case_id' => $case, 'title' => 'Plan', 'purpose' => 'Purpose', 'goals' => 'Goals', 'action_items' => 'Actions', 'progress_state' => 'in_progress'])->assertCreated();

        $this->withToken($counsellorToken)->getJson('/api/v1/schools/'.$school->public_id.'/wellbeing-summaries')->assertOk()->assertJsonPath('data.items.0.case.category', 'support')->assertJsonPath('data.items.0.summary_status', 'available')->assertJsonMissing(['PRIVATE NOTE MUST NOT APPEAR'])->assertJsonMissingPath('data.items.0.notes');
    }

    public function test_safeguarding_leadership_reads_school_summaries_and_unassigned_roles_are_denied(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'wellbeing-leadership@example.com', 'wellbeing-leadership-password');
        $counsellor = $this->memberInSchool($school, 'wellbeing-assigned@example.com', 'counsellor');
        $teacher = $this->memberInSchool($school, 'wellbeing-teacher@example.com', 'teacher');
        $adminToken = $this->loginAndSelect($admin, $school, 'wellbeing-leadership-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school, 'CNS-WELLBEING-LEAD');
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'wellbeing-assigned-password');
        $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-cases', ['assignment_id' => $assignment, 'title' => 'Case', 'category' => 'safeguarding'])->assertCreated();
        $teacherToken = $this->loginAndSelect($teacher, $school, 'wellbeing-teacher-password');

        $this->withToken($adminToken)->getJson('/api/v1/schools/'.$school->public_id.'/wellbeing-summaries')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->withToken($teacherToken)->getJson('/api/v1/schools/'.$school->public_id.'/wellbeing-summaries')->assertNotFound();
    }

    public function test_revoked_assignment_blocks_wellbeing_summary_access(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'wellbeing-revoke-admin@example.com', 'wellbeing-revoke-admin-password');
        $counsellor = $this->memberInSchool($school, 'wellbeing-revoke-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'wellbeing-revoke-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school, 'CNS-WELLBEING-REVOKE');
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'wellbeing-revoke-counsellor-password');
        $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-cases', ['assignment_id' => $assignment, 'title' => 'Case', 'category' => 'support'])->assertCreated();
        $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments/'.$assignment.'/revoke', ['reason' => 'Assignment ended'])->assertOk();

        $this->withToken($counsellorToken)->getJson('/api/v1/schools/'.$school->public_id.'/wellbeing-summaries')->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    private function createStaff(string $token, School $school, UserIdentity $identity): string
    {
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $identity->id)->firstOrFail();
        $staff = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff', ['membership_id' => $membership->public_id, 'staff_number' => 'CNS-WELLBEING-001', 'legal_name' => 'Fictional Counsellor'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff/'.$staff.'/activate')->assertOk();

        return $staff;
    }

    private function createEnrolledStudent(string $token, School $school, string $number = 'CNS-WELLBEING-TEST'): string
    {
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => $number, 'display_name' => 'Fictional Wellbeing Student'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/activate')->assertOk();
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Wellbeing Session '.$number, 'code' => 'WB'.substr($number, -3), 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Wellbeing Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Wellbeing Level '.$number, 'code' => 'WL'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Wellbeing Section', 'code' => 'WS'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $arm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Wellbeing Arm', 'code' => 'WA'.substr($number, -3), 'capacity' => 30])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/enrollments', ['session_id' => $session, 'term_id' => $term, 'level_id' => $level, 'section_id' => $section, 'class_arm_id' => $arm, 'start_date' => '2025-09-10'])->assertCreated();

        return $student;
    }

    /** @return array{0:School, 1:UserIdentity} */
    private function schoolWithRole(string $role, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Wellbeing School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, $role);

        return [$school, $identity];
    }

    private function memberInSchool(School $school, string $email, string $role): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword(str_replace('@example.com', '-password', $email))->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $this->assignRole($school, $identity, $role);

        return $identity;
    }

    private function assignRole(School $school, UserIdentity $identity, string $role): void
    {
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
