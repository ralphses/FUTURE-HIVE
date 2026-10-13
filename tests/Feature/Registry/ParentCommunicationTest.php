<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\ParentCommunicationMessage;
use App\Contexts\Registry\Domain\Models\ParentCommunicationThread;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tests\TestCase;

final class ParentCommunicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_guardian_can_open_read_and_idempotently_reply_to_a_thread(): void
    {
        [$school, $guardian, $student] = $this->fixture();
        $token = $this->login($guardian, 'parent-password')->json('data.access_token');
        $create = $this->withToken($token)->postJson('/api/v1/me/parent-communications', ['student' => $student->public_id, 'title' => 'Fictional question', 'body' => 'Please confirm the fictional timetable.'])->assertCreated();
        $thread = $create->json('data.thread.id');
        $this->withToken($token)->postJson('/api/v1/me/parent-communications/'.$thread.'/messages', ['body' => 'Please confirm the fictional timetable.'], ['Idempotency-Key' => 'parent-message-1'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/me/parent-communications/'.$thread.'/messages', ['body' => 'Please confirm the fictional timetable.'], ['Idempotency-Key' => 'parent-message-1'])->assertCreated();
        self::assertSame(2, ParentCommunicationMessage::query()->where('thread_id', ParentCommunicationThread::query()->where('public_id', $thread)->value('id'))->count());
        $this->withToken($token)->getJson('/api/v1/me/parent-communications/'.$thread)->assertOk()->assertJsonPath('data.thread.status', 'open');
    }

    public function test_pending_or_cross_school_guardian_threads_are_not_visible(): void
    {
        [$school, $guardian, $student] = $this->fixture();
        $other = $this->createSchool('Other Communication School');
        $otherStudent = TenantContext::runInternal(TenantContext::forSchool($other->id, (string) $other->public_id), 'other communication fixture', fn (): Student => Student::create(['school_id' => $other->id, 'student_number' => 'OTHER-COMM-001', 'display_name' => 'Other Fictional Student', 'admission_date' => '2025-09-01', 'status' => 'active']));
        $token = $this->login($guardian, 'parent-password')->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/me/parent-communications', ['student' => $otherStudent->public_id, 'title' => 'Wrong school', 'body' => 'No access.'])->assertNotFound();
        TenantContext::runInternal(TenantContext::fromMembership(SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $guardian->id)->firstOrFail()), 'revoke communication fixture', fn (): int => StudentGuardianRelationship::query()->where('student_id', $student->id)->update(['status' => 'revoked', 'revoked_at' => now()]));
        $this->withToken($token)->getJson('/api/v1/me/parent-communications')->assertOk()->assertJsonPath('data.threads', []);
    }

    /** @return array{0: School, 1: UserIdentity, 2: Student} */
    private function fixture(): array
    {
        $school = $this->createSchool('Communication School');
        $guardian = UserIdentity::factory()->withPassword('parent-password')->create(['name' => 'Fictional Guardian']);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $guardian->id, 'status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'parent_guardian')->value('id'), 'assigned_by' => $guardian->id, 'assigned_at' => now()]);
        $profile = GuardianProfile::create(['user_id' => $guardian->id, 'display_name' => 'Fictional Guardian']);
        $student = TenantContext::runInternal(TenantContext::fromMembership($membership), 'communication fixture', function () use ($school, $profile, $guardian): Student {
            $student = Student::create(['school_id' => $school->id, 'student_number' => 'COMM-001', 'display_name' => 'Fictional Learner', 'admission_date' => '2025-09-01', 'status' => 'active']);
            StudentGuardianRelationship::create(['school_id' => $school->id, 'student_id' => $student->id, 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'active', 'verified_at' => now(), 'created_by' => $guardian->id]);

            return $student;
        });

        return [$school, $guardian, $student];
    }

    private function createSchool(string $name): School
    {
        return School::factory()->create(['name' => $name]);
    }

    /** @return TestResponse<JsonResponse> */
    private function login(UserIdentity $identity, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk();
    }
}
