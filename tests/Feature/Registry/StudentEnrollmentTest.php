<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentEnrollmentTest extends TestCase
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

    public function test_active_student_can_be_placed_and_enrollment_can_be_ended(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'enrollment-password');
        $token = $this->loginAndSelect($identity, $school, 'enrollment-password');
        $student = $this->withToken($token)->postJson($this->studentPath($school), ['student_number' => 'ENR-001', 'display_name' => 'Fictional Learner'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($this->studentPath($school).'/'.$student.'/activate')->assertOk();
        [$session, $term, $level, $section, $classArm] = $this->academicSetup($school, $token);
        $path = $this->studentPath($school).'/'.$student.'/enrollments';

        $enrollment = $this->withToken($token)->postJson($path, ['session_id' => $session, 'term_id' => $term, 'level_id' => $level, 'section_id' => $section, 'class_arm_id' => $classArm, 'start_date' => '2025-09-10'])->assertCreated()->json('data.id');
        self::assertSame(1, StudentEnrollment::query()->count());
        $this->withToken($token)->postJson($path.'/'.$enrollment.'/end', ['reason' => 'Fictional administrative closure'])->assertOk()->assertJsonPath('data.status', 'ended');
    }

    public function test_capacity_and_duplicate_active_enrollment_are_rejected(): void
    {
        [$school, $identity] = $this->schoolWithRole('proprietor', 'capacity-password');
        $token = $this->loginAndSelect($identity, $school, 'capacity-password');
        $student = $this->withToken($token)->postJson($this->studentPath($school), ['student_number' => 'ENR-002', 'display_name' => 'Capacity Learner'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($this->studentPath($school).'/'.$student.'/activate')->assertOk();
        [$session, $term, $level, $section, $classArm] = $this->academicSetup($school, $token, 1);
        $payload = ['session_id' => $session, 'term_id' => $term, 'level_id' => $level, 'section_id' => $section, 'class_arm_id' => $classArm, 'start_date' => '2025-09-10'];
        $path = $this->studentPath($school).'/'.$student.'/enrollments';
        $this->withToken($token)->postJson($path, $payload)->assertCreated();
        $this->withToken($token)->postJson($path, $payload)->assertUnprocessable();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $role, string $password): array
    {
        $school = School::factory()->create(['name' => 'Fictional Enrollment School']);
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function studentPath(School $school): string
    {
        return '/api/v1/schools/'.$school->public_id.'/students';
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private function academicSetup(School $school, string $token, int $capacity = 30): array
    {
        $session = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions', ['name' => 'Enrollment Session', 'code' => 'ENR', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/activate', ['reason' => 'Open'])->assertOk();
        $term = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms', ['name' => 'First Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/activate', ['reason' => 'Open'])->assertOk();
        $level = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels', ['name' => 'Primary One', 'code' => 'P1', 'sequence' => 1])->assertCreated()->json('data.id');
        $section = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections', ['name' => 'Blue', 'code' => 'BLUE', 'sequence' => 1])->assertCreated()->json('data.id');
        $classArm = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/academic-levels/'.$level.'/sections/'.$section.'/class-arms', ['name' => 'Blue Arm', 'code' => 'BLUE', 'capacity' => $capacity])->assertCreated()->json('data.id');

        return [$session, $term, $level, $section, $classArm];
    }
}
