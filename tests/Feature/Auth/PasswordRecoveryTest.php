<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Domain\Models\PasswordResetChallenge;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePasswordResetCodeDelivery;
use Tests\TestCase;

final class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
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

    public function test_forgot_password_returns_the_same_generic_response_for_known_and_unknown_contacts(): void
    {
        $identity = $this->verifiedIdentity();
        $fake = $this->fakeDelivery();

        $known = $this->postJson('/api/v1/auth/password/forgot', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
        ]);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', [
            'login' => 'unknown@example.test',
        ]);

        $known->assertAccepted();
        $unknown->assertAccepted();
        self::assertSame($known->json('data.message'), $unknown->json('data.message'));
        self::assertSame($identity->contacts()->firstOrFail()->canonical_value, $fake->contact);
        $this->assertDatabaseCount('password_reset_challenges', 1);
    }

    public function test_phone_recovery_canonicalizes_the_international_contact(): void
    {
        $identity = $this->verifiedIdentity();
        $identity->contacts()->update([
            'type' => 'phone',
            'canonical_value' => '+2348031234567',
            'verified_at' => now(),
        ]);
        $fake = $this->fakeDelivery();

        $this->postJson('/api/v1/auth/password/forgot', ['login' => '+234 803 123 4567'])
            ->assertAccepted();

        self::assertSame('+2348031234567', $fake->contact);
    }

    public function test_reset_sets_a_password_and_revokes_existing_sessions(): void
    {
        $identity = $this->verifiedIdentity();
        $fake = $this->fakeDelivery();
        $login = $this->login($identity, 'correct-password');

        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'test@example.com'])
            ->assertAccepted();
        $challenge = PasswordResetChallenge::query()->firstOrFail();

        $this->postJson('/api/v1/auth/password/reset', [
            'login' => 'test@example.com',
            'code' => $fake->code,
            'password' => 'new-secure-pass',
            'password_confirmation' => 'new-secure-pass',
        ])->assertOk()->assertJsonPath('data.password_reset', true);

        $this->assertDatabaseHas('users', ['id' => $identity->id]);
        self::assertTrue(Hash::check('new-secure-pass', $identity->fresh()->getAuthPassword()));
        $this->assertDatabaseHas('auth_sessions', [
            'public_id' => $login->json('data.session_id'),
            'revoked_reason' => 'password_reset',
        ]);
        $this->assertDatabaseHas('password_reset_challenges', [
            'id' => $challenge->id,
        ]);
        self::assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_reset_code_is_single_use_and_stored_only_as_a_hash(): void
    {
        $identity = $this->verifiedIdentity();
        $fake = $this->fakeDelivery();
        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'test@example.com']);
        $challenge = PasswordResetChallenge::query()->firstOrFail();

        $this->resetPassword($fake->code)->assertOk();
        $this->resetPassword($fake->code)->assertUnauthorized();

        self::assertNotSame($fake->code, $challenge->fresh()->getRawOriginal('code_hash'));
        self::assertNull($challenge->getAttribute('code'));
        self::assertNotNull($identity->fresh()->password);
    }

    public function test_expired_or_exhausted_codes_are_rejected(): void
    {
        $this->verifiedIdentity();
        $fake = $this->fakeDelivery();
        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'test@example.com']);
        $challenge = PasswordResetChallenge::query()->firstOrFail();
        $challenge->update(['expires_at' => now()->subSecond()]);

        $this->resetPassword($fake->code)->assertUnauthorized();

        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'test@example.com']);
        $challenge = PasswordResetChallenge::query()->latest('id')->firstOrFail();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->resetPassword('000000');
        }

        $this->assertDatabaseHas('password_reset_challenges', [
            'id' => $challenge->id,
            'revoked_reason' => 'attempt_limit',
        ]);
    }

    public function test_authenticated_password_change_keeps_current_session_and_revokes_other_sessions(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $first = $this->login($identity, 'correct-password');
        $second = $this->login($identity, 'correct-password');

        $this->withToken($first->json('data.access_token'))
            ->postJson('/api/v1/auth/password/change', [
                'current_password' => 'correct-password',
                'password' => 'changed-secure-pass',
                'password_confirmation' => 'changed-secure-pass',
            ])
            ->assertOk()
            ->assertJsonPath('data.password_changed', true);

        $this->withToken($first->json('data.access_token'))->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($second->json('data.access_token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_policy_rejects_short_long_and_common_passwords(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $token = $this->login($identity, 'correct-password')->json('data.access_token');

        foreach ([str_repeat('a', 11), str_repeat('a', 129), 'passwordpassword'] as $password) {
            $this->withToken($token)
                ->postJson('/api/v1/auth/password/change', [
                    'current_password' => 'correct-password',
                    'password' => $password,
                    'password_confirmation' => $password,
                ])
                ->assertUnprocessable();
        }
    }

    public function test_invalid_current_password_is_generic_and_does_not_change_the_credential(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $token = $this->login($identity, 'correct-password')->json('data.access_token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/password/change', [
                'current_password' => 'wrong-password',
                'password' => 'changed-secure-pass',
                'password_confirmation' => 'changed-secure-pass',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_FAILED');

        self::assertTrue(Hash::check('correct-password', $identity->fresh()->getAuthPassword()));
    }

    private function verifiedIdentity(): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $identity->contacts()->update([
            'canonical_value' => 'test@example.com',
            'verified_at' => now(),
        ]);

        return $identity;
    }

    private function fakeDelivery(): FakePasswordResetCodeDelivery
    {
        $fake = new FakePasswordResetCodeDelivery;
        $this->app->instance(PasswordResetCodeDelivery::class, $fake);

        return $fake;
    }

    /** @return TestResponse<JsonResponse> */
    private function resetPassword(string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/reset', [
            'login' => 'test@example.com',
            'code' => $code,
            'password' => 'new-secure-pass',
            'password_confirmation' => 'new-secure-pass',
        ]);
    }

    /** @return TestResponse<JsonResponse> */
    private function login(UserIdentity $identity, string $password): TestResponse
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ]);

        return $response->assertOk();
    }
}
