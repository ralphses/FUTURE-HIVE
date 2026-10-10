<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Observability\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentGuardianAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_leadership_can_read_student_history_without_sensitive_fields(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'audit-admin@example.com');
        $token = $this->loginAndSelect($admin, $school);
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'AUD-001', 'display_name' => 'Fictional Audit Learner'])->assertCreated()->json('data.id');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/students/'.$student.'/audit-history')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'student.admitted')
            ->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('data.0.school_id')
            ->assertJsonMissingPath('data.0.actor_internal_id');
    }

    public function test_guardian_history_is_limited_to_the_selected_school_relationships(): void
    {
        [$school, $admin] = $this->schoolWithRole('school_admin', 'guardian-audit-admin@example.com');
        $token = $this->loginAndSelect($admin, $school);
        $student = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'AUD-002', 'display_name' => 'Fictional Guardian Learner'])->assertCreated()->json('data.id');
        $guardian = UserIdentity::factory()->withPassword('guardian-password')->create();
        $profile = GuardianProfile::query()->create(['user_id' => $guardian->id, 'display_name' => 'Fictional Guardian']);
        $relationship = StudentGuardianRelationship::query()->create(['school_id' => $school->id, 'student_id' => Student::query()->where('public_id', $student)->value('id'), 'guardian_profile_id' => $profile->id, 'relationship_type' => 'parent', 'status' => 'pending', 'created_by' => $admin->id]);
        (new RecordAuditEventAction(app(SensitiveDataRedactor::class)))->execute(new AuditEventData(action: 'guardian.relationship_created', subjectType: 'student_guardian_relationship', subjectPublicId: (string) $relationship->public_id, schoolId: $school->id, actorId: $admin->id, stateTransition: ['from' => null, 'to' => 'pending']));

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/guardians/'.$guardian->public_id.'/audit-history')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_unprivileged_members_cannot_read_audit_history(): void
    {
        [$school] = $this->schoolWithRole('school_admin', 'audit-permission-admin@example.com');
        $member = UserIdentity::factory()->withPassword('audit-reader-password')->create();
        SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $member->id, 'status' => 'active', 'joined_at' => now()]);
        $token = $this->loginAndSelect($member, $school, 'audit-reader-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/students/random/audit-history')->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Audit School']);
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password = 'password'): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
