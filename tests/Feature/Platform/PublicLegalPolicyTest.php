<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\LegalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicLegalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $this->privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key'] ?? null);
        $this->publicKey = $details['key'];
        config([
            'auth.jwt.private_key' => $this->privateKey,
            'auth.jwt.public_keys' => ['test-key' => $this->publicKey],
            'auth.jwt.current_kid' => 'test-key',
            'auth.jwt.issuer' => 'https://schoolos.test',
            'auth.jwt.audience' => 'schoolos-api',
        ]);
    }

    public function test_public_readers_receive_only_published_policies_in_deterministic_order(): void
    {
        LegalPolicy::query()->create([
            'policy_type' => 'privacy',
            'title' => 'Privacy policy',
            'sections' => [['heading' => 'Privacy', 'body' => 'Fictional safe policy text.']],
            'version' => 1,
            'status' => 'published',
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        LegalPolicy::query()->create([
            'policy_type' => 'terms',
            'title' => 'Terms',
            'sections' => [],
            'version' => 2,
            'status' => 'published',
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        $draft = LegalPolicy::query()->create([
            'policy_type' => 'privacy',
            'title' => 'Draft privacy',
            'sections' => [],
            'version' => 2,
            'status' => 'draft',
        ]);
        $retired = LegalPolicy::query()->create([
            'policy_type' => 'terms',
            'title' => 'Retired terms',
            'sections' => [],
            'version' => 1,
            'status' => 'retired',
        ]);

        $response = $this->getJson('/api/v1/public/legal-policies')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['policy_type' => 'privacy'])
            ->assertJsonFragment(['policy_type' => 'terms']);

        self::assertSame('privacy', $response->json('data.0.policy_type'));
        $response->assertJsonMissing(['title' => 'Draft privacy'])->assertJsonMissing(['title' => 'Retired terms']);

        $published = LegalPolicy::query()->where('policy_type', 'privacy')->where('status', 'published')->firstOrFail();
        $this->getJson('/api/v1/public/legal-policies/'.$published->public_id)
            ->assertOk()
            ->assertJsonPath('data.policy.sections.0.body', 'Fictional safe policy text.')
            ->assertJsonMissingPath('data.policy.created_by')
            ->assertJsonMissingPath('data.policy.internal_id');

        $this->getJson('/api/v1/public/legal-policies/'.$draft->public_id)->assertNotFound();
        $this->getJson('/api/v1/public/legal-policies/'.$retired->public_id)->assertNotFound();
    }

    public function test_authenticated_identity_can_accept_one_exact_published_version_idempotently(): void
    {
        $policy = LegalPolicy::query()->create([
            'policy_type' => 'privacy',
            'title' => 'Privacy policy',
            'sections' => [['heading' => 'Privacy', 'body' => 'Fictional safe policy text.']],
            'version' => 1,
            'status' => 'published',
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        $identity = UserIdentity::factory()->withPassword('legal-password')->create();
        $token = $this->login($identity, 'legal-password');

        $this->withToken($token)->getJson('/api/v1/me/legal-consents')
            ->assertOk()
            ->assertJsonPath('data.consents', []);

        $first = $this->withToken($token)->postJson('/api/v1/me/legal-consents', [
            'policy' => $policy->public_id,
            'actor_id' => 999999,
            'accepted_at' => now()->subYear()->toISOString(),
        ])->assertOk()->assertJsonPath('data.consent.policy_id', $policy->public_id);

        $second = $this->withToken($token)->postJson('/api/v1/me/legal-consents', ['policy' => $policy->public_id])->assertOk();

        self::assertSame($first->json('data.consent.id'), $second->json('data.consent.id'));
        self::assertDatabaseCount('legal_consents', 1);
        $this->withToken($token)->getJson('/api/v1/me/legal-consents')
            ->assertOk()
            ->assertJsonCount(1, 'data.consents')
            ->assertJsonMissingPath('data.consents.0.user_id')
            ->assertJsonMissingPath('data.consents.0.internal_id');
        self::assertDatabaseHas('audit_events', ['action' => 'platform.legal_consent.accepted']);
    }

    public function test_unpublished_or_invalid_versions_cannot_be_accepted(): void
    {
        $draft = LegalPolicy::query()->create([
            'policy_type' => 'terms',
            'title' => 'Draft terms',
            'sections' => [],
            'version' => 1,
            'status' => 'draft',
        ]);
        $this->postJson('/api/v1/me/legal-consents', ['policy' => $draft->public_id])->assertUnauthorized();
        $identity = UserIdentity::factory()->withPassword('legal-password')->create();
        $token = $this->login($identity, 'legal-password');

        $this->withToken($token)->postJson('/api/v1/me/legal-consents', ['policy' => $draft->public_id])->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/me/legal-consents', ['policy' => 'not-a-uuid'])->assertUnprocessable();
    }

    private function login(UserIdentity $identity, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ])->assertOk()->json('data.access_token');
    }
}
