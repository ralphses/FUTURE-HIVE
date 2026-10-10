<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudentAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_admission_is_tenant_scoped_and_has_a_uuid_public_identifier(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'student-admit@example.com');
        $token = $this->loginAndSelect($identity, $school);
        $response = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'STU-001', 'display_name' => 'Fictional Learner', 'metadata' => ['source' => 'demo']])->assertCreated();

        $response->assertJsonPath('data.student_number', 'STU-001')->assertJsonPath('data.status', 'pending');
        self::assertTrue(Str::isUuid((string) $response->json('data.id')));
        $this->assertDatabaseHas('students', ['school_id' => $school->id, 'student_number' => 'STU-001', 'status' => 'pending']);
        self::assertDatabaseCount('users', 1);
        self::assertDatabaseCount('school_memberships', 1);
    }

    public function test_duplicate_student_numbers_are_rejected_only_within_one_school(): void
    {
        [$school, $identity] = $this->schoolWithRole('proprietor', 'student-duplicate@example.com');
        $token = $this->loginAndSelect($identity, $school);
        $path = '/api/v1/schools/'.$school->public_id.'/students';
        $this->withToken($token)->postJson($path, ['student_number' => 'STU-002', 'display_name' => 'First Learner'])->assertCreated();
        $this->withToken($token)->postJson($path, ['student_number' => 'STU-002', 'display_name' => 'Second Learner'])->assertUnprocessable();

        [$otherSchool, $otherIdentity] = $this->schoolWithRole('school_admin', 'student-other@example.com');
        $otherToken = $this->loginAndSelect($otherIdentity, $otherSchool);
        $this->withToken($otherToken)->postJson('/api/v1/schools/'.$otherSchool->public_id.'/students', ['student_number' => 'STU-002', 'display_name' => 'Other Learner'])->assertCreated();
    }

    public function test_student_lifecycle_is_server_controlled_and_retains_history(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'student-lifecycle@example.com');
        $token = $this->loginAndSelect($identity, $school);
        $path = '/api/v1/schools/'.$school->public_id.'/students';
        $student = $this->withToken($token)->postJson($path, ['student_number' => 'STU-003', 'display_name' => 'Lifecycle Learner', 'status' => 'active', 'school_id' => 999])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($path.'/'.$student.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->postJson($path.'/'.$student.'/withdraw')->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->withToken($token)->postJson($path.'/'.$student.'/activate')->assertUnprocessable();
        $this->assertDatabaseHas('students', ['student_number' => 'STU-003', 'status' => 'withdrawn']);
    }

    public function test_cross_school_student_access_is_not_confirmed(): void
    {
        [$school, $identity] = $this->schoolWithRole('school_admin', 'student-scope@example.com');
        $token = $this->loginAndSelect($identity, $school);
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'STU-004', 'display_name' => 'Scoped Learner'])->assertCreated()->json('data.id');
        [$otherSchool, $otherIdentity] = $this->schoolWithRole('school_admin', 'student-scope-other@example.com');
        $otherToken = $this->loginAndSelect($otherIdentity, $otherSchool);

        $this->withToken($otherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/students/'.$student)->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Student School']);
        $identity = UserIdentity::factory()->withPassword('student-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'student-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
