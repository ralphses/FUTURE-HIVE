<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
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

    public function test_email_login_returns_an_rs256_access_token_and_refresh_token(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $contact = $identity->contacts()->firstOrFail();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $contact->canonical_value,
            'password' => 'correct-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 600)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'session_id']]);

        $token = $this->parseToken($response->json('data.access_token'));

        self::assertSame('RS256', $token->headers()->get('alg'));
        self::assertSame('test-key', $token->headers()->get('kid'));
        self::assertSame($identity->public_id, $token->claims()->get('sub'));
        self::assertSame('access', $token->headers()->get('typ'));
        self::assertArrayNotHasKey('school_id', $token->claims()->all());
        $this->assertDatabaseCount('auth_sessions', 1);
    }

    public function test_phone_login_uses_the_canonical_phone_contact(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $contact = $identity->contacts()->firstOrFail();
        $contact->update(['type' => 'phone', 'canonical_value' => '+2348031234567']);

        $this->postJson('/api/v1/auth/login', [
            'login' => '+234 803 123 4567',
            'password' => 'correct-password',
        ])->assertOk();
    }

    public function test_invalid_and_unknown_credentials_return_the_same_generic_401_response(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $login = $identity->contacts()->firstOrFail()->canonical_value;

        $invalid = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'wrong-password']);
        $unknown = $this->postJson('/api/v1/auth/login', ['login' => 'unknown@example.test', 'password' => 'wrong-password']);

        $invalid->assertUnauthorized()->assertJsonPath('error.message', 'Authentication failed.');
        $unknown->assertUnauthorized()->assertJsonPath('error.message', 'Authentication failed.');
        self::assertSame($invalid->json('error.code'), $unknown->json('error.code'));
    }

    public function test_credentialless_identity_is_denied(): void
    {
        $identity = UserIdentity::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_refresh_rotates_the_token_and_invalidates_the_previous_token(): void
    {
        $login = $this->login();
        $firstRefresh = $login->json('data.refresh_token');

        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $firstRefresh]);
        $secondRefresh = $rotated->json('data.refresh_token');

        $rotated->assertOk();
        self::assertNotSame($firstRefresh, $secondRefresh);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $firstRefresh])->assertUnauthorized();
        self::assertSame(2, AuthSession::query()->count());
    }

    public function test_refresh_token_reuse_revokes_the_entire_token_family(): void
    {
        $firstRefresh = $this->login()->json('data.refresh_token');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $firstRefresh])->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $firstRefresh])->assertUnauthorized();

        self::assertSame(2, AuthSession::query()->whereNotNull('revoked_at')->count());
    }

    public function test_authenticated_me_and_logout_use_the_bearer_session(): void
    {
        $login = $this->login();
        $accessToken = $login->json('data.access_token');

        $this->withToken($accessToken)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure(['data' => ['public_id', 'name', 'contacts']]);

        $this->withToken($accessToken)->postJson('/api/v1/auth/logout')
            ->assertOk();
        $this->withToken($accessToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_all_revokes_all_sessions_for_the_identity(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $login = fn () => $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => 'correct-password',
        ]);
        $first = $login();
        $second = $login();

        $this->withToken($first->json('data.access_token'))->postJson('/api/v1/auth/logout-all')->assertOk();
        $this->withToken($second->json('data.access_token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_browser_login_sets_secure_refresh_and_csrf_cookies_and_requires_csrf_for_refresh(): void
    {
        $login = $this->login('browser');
        $cookies = $login->headers->getCookies();
        $refresh = $this->cookieNamed($cookies, 'refresh_token');
        $csrf = $this->cookieNamed($cookies, 'XSRF-TOKEN');

        self::assertTrue($refresh->isHttpOnly());
        self::assertSame('lax', strtolower($refresh->getSameSite() ?? ''));

        $this->withCookie('refresh_token', $refresh->getValue())
            ->withCookie('XSRF-TOKEN', $csrf->getValue())
            ->postJson('/api/v1/auth/refresh', ['client' => 'browser'])
            ->assertStatus(419);
    }

    public function test_missing_or_invalid_bearer_tokens_return_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken('not-a-jwt')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited_without_exposing_identity_existence(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $login = $identity->contacts()->firstOrFail()->canonical_value;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'wrong-password']);
        }

        $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'wrong-password'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'HTTP_ERROR');
    }

    /** @return TestResponse<JsonResponse> */
    private function login(string $client = 'api'): TestResponse
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();

        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => 'correct-password',
            'client' => $client,
        ]);
    }

    private function parseToken(string $value): Plain
    {
        $token = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($this->privateKey),
            InMemory::plainText($this->publicKey),
        )->parser()->parse($value);

        if (! $token instanceof Plain) {
            self::fail('Expected a plain JWT.');
        }

        return $token;
    }

    /** @param array<int, Cookie> $cookies */
    private function cookieNamed(array $cookies, string $name): Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        self::fail("Cookie {$name} was not set.");
    }
}
