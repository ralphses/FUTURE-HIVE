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

final class GuardianProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardian_profile_is_reused_across_schools_without_creating_an_identity(): void
    {
        [$school, $admin] = $this->schoolWithAdmin('guardian-profile@example.com');
        $token = $this->loginAndSelect($admin, $school);
        $student = $this->admit($token, $school, 'GUARD-001');
        $guardian = UserIdentity::factory()->create(['name' => 'Fictional Guardian']);

        $path = "/api/v1/schools/{$school->public_id}/students/{$student}/guardian-relationships";
        $this->withToken($token)->postJson($path, ['guardian_id' => $guardian->public_id, 'relationship_type' => 'parent'])->assertCreated();
        $this->withToken($token)->getJson("/api/v1/schools/{$school->public_id}/guardians")->assertOk()->assertJsonPath('data.items.0.guardian_id', $guardian->public_id);

        self::assertDatabaseCount('users', 2);
        self::assertDatabaseCount('guardian_profiles', 1);
    }

    public function test_relationships_start_pending_and_duplicate_active_links_are_rejected(): void
    {
        [$school, $admin] = $this->schoolWithAdmin('guardian-relationship@example.com');
        $token = $this->loginAndSelect($admin, $school);
        $student = $this->admit($token, $school, 'GUARD-002');
        $guardian = UserIdentity::factory()->create();
        $path = "/api/v1/schools/{$school->public_id}/students/{$student}/guardian-relationships";
        $payload = ['guardian_id' => $guardian->public_id, 'relationship_type' => 'guardian'];

        $this->withToken($token)->postJson($path, $payload)->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->withToken($token)->postJson($path, $payload)->assertUnprocessable();
        self::assertDatabaseCount('student_guardian_relationships', 1);
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithAdmin(string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Guardian Academy']);
        $identity = UserIdentity::factory()->withPassword('guardian-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'guardian-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function admit(string $token, School $school, string $number): string
    {
        return $this->withToken($token)->postJson("/api/v1/schools/{$school->public_id}/students", ['student_number' => $number, 'display_name' => 'Fictional Learner'])->assertCreated()->json('data.id');
    }
}
