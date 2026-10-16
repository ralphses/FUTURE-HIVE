<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicKnowledgeSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_operations_can_create_publish_and_retire_a_jamb_source(): void
    {
        [$token] = $this->platformToken('platform_ops');
        $base = '/api/v1/platform/academic-knowledge-sources';

        $source = $this->withToken($token)->postJson($base, [
            'source_type' => 'jamb',
            'label' => 'JAMB 2026 requirements',
            'description' => 'Approved source summary',
            'content' => ['requirements' => [['label' => 'Medicine', 'summary' => 'Official subject summary', 'subjects' => ['Biology', 'Chemistry']]]],
            'effective_at' => now()->subDay()->toISOString(),
        ])->assertCreated()->assertJsonPath('data.source.status', 'draft')->json('data.source.id');

        $this->withToken($token)->postJson($base.'/'.$source.'/publish')
            ->assertOk()->assertJsonPath('data.source.status', 'published');
        $this->withToken($token)->getJson($base.'/'.$source)
            ->assertOk()->assertJsonPath('data.source.content.requirements.0.label', 'Medicine');
        $this->withToken($token)->postJson($base.'/'.$source.'/retire')
            ->assertOk()->assertJsonPath('data.source.status', 'retired');
        $this->withToken($token)->postJson($base.'/'.$source.'/publish')->assertNotFound();
    }

    public function test_invalid_scope_unknown_content_and_unauthorized_roles_are_rejected(): void
    {
        [$token] = $this->platformToken('platform_ops');
        $base = '/api/v1/platform/academic-knowledge-sources';
        $payload = [
            'source_type' => 'institution',
            'label' => 'Institution requirements',
            'content' => ['requirements' => [['label' => 'Science', 'summary' => 'Bounded summary', 'unexpected' => 'blocked']]],
            'effective_at' => now()->toISOString(),
        ];

        $this->withToken($token)->postJson($base, $payload)->assertUnprocessable();
        $this->withToken($token)->postJson($base, array_replace($payload, [
            'institution_reference' => 'UNI-001',
            'content' => ['requirements' => [['label' => 'Science', 'summary' => 'Bounded summary']]],
        ]))->assertCreated();

        [$schoolToken] = $this->schoolToken();
        $this->withToken($schoolToken)->getJson($base)->assertNotFound();
    }

    /** @return array{0: string} */
    private function platformToken(string $roleKey): array
    {
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = $roleKey.'-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        PlatformRoleAssignment::query()->create(['user_id' => $identity->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');

        return [$token];
    }

    /** @return array{0: string} */
    private function schoolToken(): array
    {
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $login = 'school-user-'.uniqid().'@example.test';
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'password'])->assertOk()->json('data.access_token');

        return [$token];
    }
}
