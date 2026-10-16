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

final class ProprietorExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_proprietor_dashboard_returns_safe_derived_sections(): void
    {
        [$school, $token] = $this->schoolToken('proprietor', 'executive-dashboard@example.com', 'executive-dashboard-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/proprietor-dashboard')
            ->assertOk()
            ->assertJsonPath('data.id', 'proprietor-executive-dashboard')
            ->assertJsonPath('data.school_id', $school->public_id)
            ->assertJsonStructure(['data' => ['request_id', 'sections' => ['review', 'academic_publication', 'school_setup'], 'generated_at']])
            ->assertJsonMissingPath('data.sections.review.finance.total_minor_units')
            ->assertJsonMissingPath('data.sections.review.academic.students')
            ->assertJsonMissingPath('data.sections.review.academic.raw_scores');
    }

    public function test_only_proprietor_can_read_the_executive_dashboard(): void
    {
        [$school, $token] = $this->schoolToken('principal', 'executive-principal@example.com', 'executive-principal-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/proprietor-dashboard')->assertNotFound();
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
