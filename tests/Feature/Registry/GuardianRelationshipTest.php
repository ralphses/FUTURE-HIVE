<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GuardianRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_can_be_revoked_and_history_is_retained(): void
    {
        [$school, $admin] = $this->schoolWithAdmin();
        $token = $this->loginAndSelect($admin, $school);
        $student = $this->admit($token, $school);
        $guardian = UserIdentity::factory()->create();
        $path = "/api/v1/schools/{$school->public_id}/students/{$student}/guardian-relationships";
        $relationship = $this->withToken($token)->postJson($path, ['guardian_id' => $guardian->public_id, 'relationship_type' => 'parent'])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("{$path}/{$relationship}/revoke", ['reason' => 'Fictional administrative change'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertDatabaseHas('student_guardian_relationships', ['public_id' => $relationship, 'status' => 'revoked']);
        $this->withToken($token)->getJson($path)->assertOk()->assertJsonCount(0, 'data.items');
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithAdmin(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Relationship School']);
        $identity = UserIdentity::factory()->withPassword('relationship-password')->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'relationship-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function admit(string $token, School $school): string
    {
        return $this->withToken($token)->postJson("/api/v1/schools/{$school->public_id}/students", ['student_number' => 'REL-001', 'display_name' => 'Fictional Learner'])->assertCreated()->json('data.id');
    }
}
