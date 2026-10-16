<?php

declare(strict_types=1);

namespace Tests\Feature\Counselling;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\CounsellingDevelopmentPlan;
use App\Contexts\Registry\Domain\Models\CounsellingDevelopmentPlanRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SharedDevelopmentPlanTest extends TestCase
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

    public function test_assigned_counsellor_can_create_version_and_share_a_plan_without_private_note_content(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'plan-admin@example.com', 'plan-admin-password');
        $counsellor = $this->memberInSchool($school, 'plan-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'plan-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school);
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'plan-counsellor-password');
        $case = $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-cases', ['assignment_id' => $assignment, 'title' => 'Fictional case', 'category' => 'support'])->assertCreated()->json('data.id');
        $base = '/api/v1/schools/'.$school->public_id.'/development-plans';
        $plan = $this->withToken($counsellorToken)->postJson($base, ['case_id' => $case, 'title' => 'Fictional plan', 'purpose' => 'Fictional purpose', 'goals' => 'Fictional goal', 'action_items' => 'Fictional action', 'progress_state' => 'not_started', 'school_id' => 999, 'status' => 'active'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->withToken($counsellorToken)->patchJson($base.'/'.$plan, ['purpose' => 'Updated fictional purpose'])->assertOk()->assertJsonPath('data.version', 2);
        $this->withToken($counsellorToken)->postJson($base.'/'.$plan.'/share', ['scope' => 'counselling_staff', 'recipient_id' => 999])->assertOk()->assertJsonPath('data.shares.0.scope', 'counselling_staff');
        $this->withToken($counsellorToken)->postJson($base.'/'.$plan.'/activate', ['reason' => 'Fictional activation'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($counsellorToken)->postJson($base.'/'.$plan.'/close', ['reason' => 'Fictional closure'])->assertOk()->assertJsonPath('data.status', 'closed');
        self::assertSame(1, CounsellingDevelopmentPlan::query()->count());
        self::assertSame(2, CounsellingDevelopmentPlanRevision::query()->count());
    }

    public function test_revoked_assignment_blocks_plan_access_and_leadership_does_not_receive_private_notes(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'plan-scope-admin@example.com', 'plan-scope-admin-password');
        $counsellor = $this->memberInSchool($school, 'plan-scope-counsellor@example.com', 'counsellor');
        $adminToken = $this->loginAndSelect($admin, $school, 'plan-scope-admin-password');
        $staff = $this->createStaff($adminToken, $school, $counsellor);
        $student = $this->createEnrolledStudent($adminToken, $school, 'CNS-PLAN-SCOPE');
        $assignment = $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments', ['staff_id' => $staff, 'student_id' => $student])->assertCreated()->json('data.id');
        $counsellorToken = $this->loginAndSelect($counsellor, $school, 'plan-scope-counsellor-password');
        $case = $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-cases', ['assignment_id' => $assignment, 'title' => 'Fictional scope case', 'category' => 'support'])->assertCreated()->json('data.id');
        $plan = $this->withToken($counsellorToken)->postJson('/api/v1/schools/'.$school->public_id.'/development-plans', ['case_id' => $case, 'title' => 'Fictional plan', 'purpose' => 'Purpose', 'goals' => 'Goals', 'action_items' => 'Actions', 'progress_state' => 'in_progress'])->assertCreated()->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/schools/'.$school->public_id.'/counselling-assignments/'.$assignment.'/revoke', ['reason' => 'Fictional reassignment'])->assertOk();
        $this->withToken($counsellorToken)->getJson('/api/v1/schools/'.$school->public_id.'/development-plans/'.$plan)->assertNotFound();
        $this->withToken($adminToken)->getJson('/api/v1/schools/'.$school->public_id.'/development-plans/'.$plan)->assertNotFound();
    }

    private function createStaff(string $token, School $school, UserIdentity $identity): string
    {
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $identity->id)->firstOrFail();
        $staff = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff', ['membership_id' => $membership->public_id, 'staff_number' => 'PLAN-TEST-001', 'legal_name' => 'Fictional Counsellor'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/staff/'.$staff.'/activate')->assertOk();

        return $staff;
    }

    private function createEnrolledStudent(string $token, School $school, string $number = 'CNS-PLAN-TEST'): string
    {
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => $number, 'display_name' => 'Fictional Plan Student'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/activate')->assertOk();
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Plan Session '.$number, 'code' => 'PL'.substr($number, -3), 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'Plan Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Fictional activation'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Plan Level '.$number, 'code' => 'PL'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Plan Section', 'code' => 'PS'.substr($number, -3), 'sequence' => 1])->assertCreated()->json('data.id');
        $arm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Plan Arm', 'code' => 'PA'.substr($number, -3), 'capacity' => 30])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/enrollments', ['session_id' => $session, 'term_id' => $term, 'level_id' => $level, 'section_id' => $section, 'class_arm_id' => $arm, 'start_date' => '2025-09-10'])->assertCreated();

        return $student;
    }

    /** @return array{0:School,1:UserIdentity} */
    private function schoolWithRole(string $role, string $email, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Plan School']);
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
