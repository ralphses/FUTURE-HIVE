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

final class PrincipalReviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_principal_dashboard_returns_safe_read_only_sections(): void
    {
        [$school, $token] = $this->schoolToken('principal', 'principal-dashboard@example.com', 'principal-dashboard-password');

        $response = $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/principal-review-dashboard');

        $response->assertOk()
            ->assertJsonPath('data.id', 'principal-review-dashboard')
            ->assertJsonPath('data.school_id', $school->public_id)
            ->assertJsonStructure(['data' => ['request_id', 'sections' => ['academic', 'finance', 'attendance', 'imports'], 'generated_at']])
            ->assertJsonMissingPath('data.sections.finance.total_minor_units')
            ->assertJsonMissingPath('data.sections.academic.students')
            ->assertJsonMissingPath('data.sections.academic.raw_scores');
    }

    public function test_only_approved_school_leadership_can_read_the_dashboard(): void
    {
        [$school, $teacherToken] = $this->schoolToken('teacher', 'dashboard-teacher@example.com', 'dashboard-teacher-password');

        $this->withToken($teacherToken)
            ->getJson('/api/v1/schools/'.$school->public_id.'/principal-review-dashboard')
            ->assertNotFound();
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
