<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_configuration_returns_deterministic_safe_readiness_data(): void
    {
        [$school, $identity] = $this->schoolWithRole('teacher', 'readiness@example.com');
        $token = $this->loginAndSelect($identity, $school);
        $path = '/api/v1/schools/'.$school->public_id.'/academic-readiness';

        $first = $this->withToken($token)->getJson($path)->assertOk();
        $second = $this->withToken($token)->getJson($path)->assertOk();

        $first->assertJsonPath('data.ready', false)->assertJsonPath('data.checks.0.key', 'school_lifecycle')->assertJsonPath('data.checks.0.status', 'ready')->assertJsonPath('data.checks.1.key', 'active_academic_period')->assertJsonPath('data.checks.1.status', 'missing');
        self::assertSame($first->json('data'), $second->json('data'));
        self::assertArrayNotHasKey('school_id', $first->json('data'));
        self::assertArrayNotHasKey('id', $first->json('data'));
    }

    public function test_readiness_requires_the_readiness_permission(): void
    {
        [$school, $identity] = $this->schoolWithRole('teacher', 'readiness-permission@example.com');
        $permission = Permission::query()->where('key', 'academic.readiness.read')->firstOrFail();
        Role::query()->where('key', 'teacher')->firstOrFail()->permissions()->detach($permission->getKey());
        $token = $this->loginAndSelect($identity, $school);

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/academic-readiness')->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithRole(string $roleKey, string $email): array
    {
        $school = School::factory()->create(['name' => 'Fictional Readiness School']);
        $identity = UserIdentity::factory()->withPassword('readiness-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'readiness-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
