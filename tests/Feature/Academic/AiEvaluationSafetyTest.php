<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiEvaluationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_operations_can_run_a_safe_evaluation_and_review_it_once(): void
    {
        [$requestToken, $requester] = $this->platformToken('platform_ops');
        [$reviewToken] = $this->platformToken('platform_admin');

        $created = $this->withToken($requestToken)->postJson('/api/v1/platform/ai-evaluations', [
            'feature_key' => 'ADV-002',
            'fixture_set' => 'approved_safety_v1',
            'fixture_version' => '1.0.0',
            'idempotency_key' => 'evaluation-request-001',
            'raw_fixture' => ['student_id' => 123, 'contact' => 'private@example.com'],
        ])->assertCreated()
            ->assertJsonPath('data.evaluation.state', 'draft')
            ->assertJsonMissing(['raw_fixture', 'student_id', 'contact', 'requested_by', 'internal_id'])
            ->json('data.evaluation');

        $this->withToken($requestToken)->postJson('/api/v1/platform/ai-evaluations/'.$created['id'].'/run')
            ->assertOk()
            ->assertJsonPath('data.evaluation.state', 'completed')
            ->assertJsonPath('data.evaluation.release_gate_status', 'passed')
            ->assertJsonCount(6, 'data.evaluation.results');

        $this->withToken($requestToken)->postJson('/api/v1/platform/ai-evaluations/'.$created['id'].'/review', ['decision' => 'approved', 'reason' => 'Independent review.'])
            ->assertNotFound();

        $this->withToken($reviewToken)->postJson('/api/v1/platform/ai-evaluations/'.$created['id'].'/review', ['decision' => 'approved', 'reason' => 'Independent review.'])
            ->assertOk()
            ->assertJsonPath('data.evaluation.review_state', 'approved');

        $this->withToken($requestToken)->postJson('/api/v1/platform/ai-evaluations', [
            'feature_key' => 'ADV-002',
            'fixture_set' => 'approved_safety_v1',
            'fixture_version' => '1.0.0',
            'idempotency_key' => 'evaluation-request-001',
        ])->assertCreated()->assertJsonPath('data.evaluation.id', $created['id']);

        self::assertSame($requester->id, $requester->fresh()->id);
    }

    public function test_failed_safety_dimensions_block_release_approval(): void
    {
        [$token] = $this->platformToken('platform_ops');
        [$reviewToken] = $this->platformToken('platform_admin');

        $evaluation = $this->withToken($token)->postJson('/api/v1/platform/ai-evaluations', [
            'feature_key' => 'ADV-003',
            'fixture_set' => 'known_failure_safety_v1',
            'fixture_version' => '1.0.0',
            'idempotency_key' => 'evaluation-request-002',
        ])->assertCreated()->json('data.evaluation');

        $this->withToken($token)->postJson('/api/v1/platform/ai-evaluations/'.$evaluation['id'].'/run')
            ->assertOk()
            ->assertJsonPath('data.evaluation.state', 'blocked')
            ->assertJsonPath('data.evaluation.release_gate_status', 'failed')
            ->assertJsonPath('data.evaluation.issue_codes.0', 'evaluation_threshold_failed');

        $this->withToken($reviewToken)->postJson('/api/v1/platform/ai-evaluations/'.$evaluation['id'].'/review', ['decision' => 'approved', 'reason' => 'Thresholds failed.'])
            ->assertNotFound();
    }

    public function test_school_members_cannot_access_platform_evaluations(): void
    {
        [$schoolToken] = $this->schoolToken();

        $this->withToken($schoolToken)->getJson('/api/v1/platform/ai-evaluations')->assertNotFound();
    }

    /** @return array{0: string, 1: UserIdentity} */
    private function platformToken(string $roleKey): array
    {
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = $roleKey.'-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        PlatformRoleAssignment::query()->create(['user_id' => $identity->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');

        return [$token, $identity];
    }

    /** @return array{0: string, 1: UserIdentity} */
    private function schoolToken(): array
    {
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = 'school-user-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        $school = School::factory()->create();
        $membership = $school->memberships()->create(['user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        $membership->roleAssignments()->create(['role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');

        return [$token, $identity];
    }
}
