<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchoolAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_can_read_a_safe_composed_workspace(): void
    {
        [$school, $token] = $this->schoolToken('school_admin', 'admin-workspace@example.com', 'admin-workspace-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/admin-workspace')
            ->assertOk()
            ->assertJsonPath('data.id', 'school-admin-workspace')
            ->assertJsonPath('data.school_id', $school->public_id)
            ->assertJsonStructure(['data' => ['request_id', 'sections' => ['setup', 'lifecycle', 'staff', 'memberships', 'academic_context'], 'issue_codes', 'generated_at']])
            ->assertJsonMissingPath('data.sections.staff.contacts')
            ->assertJsonMissingPath('data.students')
            ->assertJsonMissingPath('data.internal_ids');
    }

    public function test_non_school_admin_roles_cannot_read_the_workspace(): void
    {
        [$school, $token] = $this->schoolToken('proprietor', 'admin-workspace-proprietor@example.com', 'admin-workspace-proprietor-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/admin-workspace')->assertNotFound();
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role, string $login, string $password): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }
}
