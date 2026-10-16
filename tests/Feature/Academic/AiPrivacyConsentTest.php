<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Contracts\AiPrivacyBoundary;
use App\Contexts\Platform\Domain\Models\LegalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiPrivacyConsentTest extends TestCase
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
        $this->publicKey = (string) ($details['key'] ?? '');
        config([
            'auth.jwt.private_key' => $this->privateKey,
            'auth.jwt.public_keys' => ['test-key' => $this->publicKey],
            'auth.jwt.current_kid' => 'test-key',
            'auth.jwt.issuer' => 'https://schoolos.test',
            'auth.jwt.audience' => 'schoolos-api',
        ]);
    }

    public function test_consent_is_exact_versioned_idempotent_and_safe(): void
    {
        $policy = LegalPolicy::query()->create([
            'policy_type' => 'ai_privacy',
            'title' => 'AI assistance privacy policy',
            'sections' => [['heading' => 'Use', 'body' => 'Fictional approved AI privacy text.']],
            'version' => 1,
            'status' => 'published',
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        $identity = UserIdentity::factory()->withPassword('ai-consent-password')->create();
        $token = $this->login($identity, 'ai-consent-password');

        $first = $this->withToken($token)->postJson('/api/v1/me/ai-consents', [
            'policy_id' => $policy->public_id,
            'purpose' => 'academic_assistance',
            'feature_scope' => 'career_guidance',
            'actor_id' => 999999,
            'accepted_at' => now()->subYear()->toISOString(),
        ])->assertCreated()
            ->assertJsonPath('data.consent.policy_id', $policy->public_id)
            ->assertJsonPath('data.consent.status', 'active')
            ->assertJsonMissing(['actor_id', 'accepted_at', 'internal_id']);

        $second = $this->withToken($token)->postJson('/api/v1/me/ai-consents', [
            'policy_id' => $policy->public_id,
            'purpose' => 'academic_assistance',
            'feature_scope' => 'career_guidance',
        ])->assertCreated();

        self::assertSame($first->json('data.consent.id'), $second->json('data.consent.id'));
        self::assertDatabaseCount('ai_consents', 1);
        self::assertDatabaseCount('ai_consent_events', 1);
        $this->withToken($token)->getJson('/api/v1/me/ai-consents')
            ->assertOk()
            ->assertJsonCount(1, 'data.consents')
            ->assertJsonMissingPath('data.consents.0.user_id');
    }

    public function test_privacy_boundary_fails_closed_redacts_payload_and_revoke_blocks_scope(): void
    {
        $identity = UserIdentity::factory()->withPassword('ai-boundary-password')->create();
        $token = $this->login($identity, 'ai-boundary-password');
        $boundary = app(AiPrivacyBoundary::class);
        $blocked = $boundary->prepare($identity, 'academic_insights', [
            'subject' => 'Science',
            'contact' => 'private@example.com',
            'token' => 'secret',
            'internal_id' => 42,
        ], true);

        self::assertFalse($blocked['allowed']);
        self::assertSame('consent_required', $blocked['issue_code']);
        self::assertSame([], $blocked['payload']);

        $policy = LegalPolicy::query()->create([
            'policy_type' => 'ai_privacy',
            'title' => 'AI assistance privacy policy',
            'sections' => [],
            'version' => 2,
            'status' => 'published',
            'effective_at' => now(),
            'published_at' => now(),
        ]);
        $this->withToken($token)->postJson('/api/v1/me/ai-consents', [
            'policy_id' => $policy->public_id,
            'purpose' => 'academic_assistance',
            'feature_scope' => 'academic_insights',
        ])->assertCreated();

        $allowed = $boundary->prepare($identity, 'academic_insights', [
            'subject' => 'Science',
            'contact' => 'private@example.com',
            'token' => 'secret',
            'internal_id' => 42,
        ], true);
        self::assertTrue($allowed['allowed']);
        self::assertSame(['subject' => 'Science'], $allowed['payload']);

        $consentId = $this->withToken($token)->getJson('/api/v1/me/ai-consents')->json('data.consents.0.id');
        $this->withToken($token)->postJson('/api/v1/me/ai-consents/'.$consentId.'/revoke')->assertOk()->assertJsonPath('data.consent.status', 'revoked');

        $revoked = $boundary->prepare($identity, 'academic_insights', ['subject' => 'Science'], true);
        self::assertFalse($revoked['allowed']);
        self::assertSame('consent_revoked', $revoked['issue_code']);
    }

    private function login(UserIdentity $identity, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ])->assertOk()->json('data.access_token');
    }
}
