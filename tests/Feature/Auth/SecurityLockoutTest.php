<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contexts\Identity\Application\Actions\AuthenticateIdentityAction;
use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Domain\Models\IdentitySecurityState;
use App\Contexts\Identity\Domain\Models\SecurityEvent;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\AuthenticationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePasswordResetCodeDelivery;
use Tests\TestCase;

final class SecurityLockoutTest extends TestCase
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

    public function test_five_failures_create_a_temporary_lockout_and_correct_password_is_denied(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $login = $identity->contacts()->firstOrFail()->canonical_value;
        $action = app(AuthenticateIdentityAction::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $action->login($login, 'wrong-password', '203.0.113.'.($attempt + 1), 'fictional-agent');
            } catch (AuthenticationFailed) {
                // Expected failure for the lockout threshold.
            }
        }

        $state = IdentitySecurityState::query()->where('user_id', $identity->id)->firstOrFail();
        self::assertSame(1, $state->lockout_level);
        self::assertNotNull($state->locked_until);

        $this->expectException(AuthenticationFailed::class);
        $action->login($login, 'correct-password', '203.0.113.10', 'fictional-agent');
    }

    public function test_expired_lockout_allows_login_and_success_resets_the_state(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $login = $identity->contacts()->firstOrFail()->canonical_value;
        $action = app(AuthenticateIdentityAction::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $action->login($login, 'wrong-password', null, null);
            } catch (AuthenticationFailed) {
                // Expected failure for the lockout threshold.
            }
        }

        $this->travel(16)->minutes();
        $action->login($login, 'correct-password', null, null);

        $state = IdentitySecurityState::query()->where('user_id', $identity->id)->firstOrFail();
        self::assertSame(0, $state->lockout_level);
        self::assertSame(0, $state->failed_login_attempts);
        self::assertNull($state->locked_until);
    }

    public function test_repeated_lockouts_escalate_to_the_next_duration(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $login = $identity->contacts()->firstOrFail()->canonical_value;
        $action = app(AuthenticateIdentityAction::class);

        for ($round = 0; $round < 2; $round++) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $action->login($login, 'wrong-password', null, null);
                } catch (AuthenticationFailed) {
                    // Expected failure for the lockout threshold.
                }
            }

            if ($round === 0) {
                $this->travel(16)->minutes();
            }
        }

        self::assertSame(2, IdentitySecurityState::query()->firstOrFail()->lockout_level);
    }

    public function test_security_events_are_append_only_and_never_store_secrets(): void
    {
        $identity = UserIdentity::factory()->withPassword('correct-password')->create();
        $login = $identity->contacts()->firstOrFail()->canonical_value;
        $action = app(AuthenticateIdentityAction::class);

        try {
            $action->login($login, 'wrong-password', '203.0.113.20', 'fictional-agent');
        } catch (AuthenticationFailed) {
            // Expected failed login event.
        }

        $event = SecurityEvent::query()->firstOrFail();
        self::assertSame('login.failed', $event->event_type);
        self::assertSame('denied', $event->outcome);
        self::assertSame($identity->id, $event->user_id);
        self::assertNotSame('test@example.com', $event->login_hash);
        self::assertNotSame('wrong-password', json_encode($event->toArray()));
        self::assertNotSame('203.0.113.20', json_encode($event->toArray()));
        self::assertSame(1, SecurityEvent::query()->count());
    }

    public function test_successful_password_recovery_clears_lockout_state(): void
    {
        $identity = UserIdentity::factory()->create();
        $login = $identity->contacts()->firstOrFail()->canonical_value;
        $state = IdentitySecurityState::query()->create([
            'user_id' => $identity->id,
            'failed_login_attempts' => 4,
            'lockout_level' => 2,
            'locked_until' => now()->addHour(),
        ]);
        $fake = new FakePasswordResetCodeDelivery;
        $this->app->instance(PasswordResetCodeDelivery::class, $fake);
        $identity->contacts()->update(['verified_at' => now()]);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => $login])->assertAccepted();
        $this->postJson('/api/v1/auth/password/reset', [
            'login' => $login,
            'code' => $fake->code,
            'password' => 'new-secure-pass',
            'password_confirmation' => 'new-secure-pass',
        ])->assertOk();

        $state = $state->fresh();
        self::assertSame(0, $state->lockout_level);
        self::assertNull($state->locked_until);
    }
}
