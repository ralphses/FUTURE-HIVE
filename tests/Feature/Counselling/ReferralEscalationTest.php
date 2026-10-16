<?php

declare(strict_types=1);

namespace Tests\Feature\Counselling;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\CounsellingReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReferralEscalationTest extends TestCase
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

    public function test_assigned_counsellor_can_request_referral_and_leadership_must_approve_as_another_actor(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'referral-admin@example.com', 'referral-admin-password');
        $counsellor = $this->memberInSchool($school, 'referral-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'referral-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school);
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'referral-counsellor-password');
        $case = $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-cases', ['assignment_id' => $assignment, 'title' => 'Fictional referral case', 'category' => 'support'])->assertCreated()->json('data.id');

        $base = '/api/v1/schools/'.$school->public_id.'/counselling-referrals';
        $referral = $this->withToken($counsellorToken)->postJson($base, ['case_id' => $case, 'title' => 'Fictional referral', 'purpose' => 'Fictional internal support', 'urgency' => 'normal', 'reason_code' => 'academic_support', 'destination_scope' => 'counselling_staff', 'status' => 'approved'])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');
        $this->withToken($counsellorToken)->postJson($base.'/'.$referral.'/approve', ['reason' => 'Self approval'])->assertNotFound();
        $this->withToken($adminToken)->postJson($base.'/'.$referral.'/approve', ['reason' => 'Fictional approval'])->assertOk()->assertJsonPath('data.status', 'approved');

        self::assertSame(1, CounsellingReferral::query()->count());
        self::assertSame('referral', CounsellingReferral::query()->firstOrFail()->type);
    }

    public function test_escalation_rejects_referral_scope_and_revoked_assignment_blocks_access(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'escalation-admin@example.com', 'escalation-admin-password');
        $counsellor = $this->memberInSchool($school, 'escalation-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'escalation-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school, 'CNS-ESCALATION-TEST');
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'escalation-counsellor-password');
        $case = $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-cases', ['assignment_id' => $assignment, 'title' => 'Fictional escalation case', 'category' => 'safeguarding'])->assertCreated()->json('data.id');

        $base = '/api/v1/schools/'.$school->public_id.'/counselling-escalations';
        $this->withToken($counsellorToken)->postJson($base, ['case_id' => $case, 'title' => 'Fictional escalation', 'purpose' => 'Fictional safeguarding support', 'urgency' => 'high', 'reason_code' => 'safeguarding_review', 'destination_scope' => 'counselling_staff'])->assertNotFound();
        $escalation = $this->withToken($counsellorToken)->postJson($base, ['case_id' => $case, 'title' => 'Fictional escalation', 'purpose' => 'Fictional safeguarding support', 'urgency' => 'high', 'reason_code' => 'safeguarding_review', 'destination_scope' => 'safeguarding_leadership'])->assertCreated()->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments/'.$assignment.'/revoke', ['reason' => 'Fictional reassignment'])->assertOk();
        $this->withToken($counsellorToken)->getJson($base.'/'.$escalation)->assertNotFound();
    }

    private function createStaff(string $token, School $school, UserIdentity $identity): string
    {
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $identity->id)->firstOrFail();
        $staff = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff', ['membership_id' => $membership->public_id, 'staff_number' => 'CNS-REF-TEST', 'legal_name' => 'Fictional Counsellor'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff/'.$staff.'/activate')->assertOk();

        return $staff;
    }

    private function createEnrolledStudent(string $token, School $school, string $number = 'CNS-REFERRAL-TEST'): string
    {
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => $number, 'display_name' => 'Fictional Referral Student'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/activate')->assertOk();
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Referral Session '.$number, 'code' => 'RF'.substr($number, -3), 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Referral Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Referral Level '.$number, 'code' => 'RL'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Referral Section', 'code' => 'RS'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $arm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Referral Arm', 'code' => 'RA'.substr($number, -3), 'capacity' => 30])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/enrollments', ['session_id' => $session, 'term_id' => $term, 'level_id' => $level, 'section_id' => $section, 'class_arm_id' => $arm, 'start_date' => '2025-09-10'])->assertCreated();

        return $student;
    }

    /** @return array{0:School,1:UserIdentity} */
    private function schoolWithRole(string $role, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Referral School']);
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
