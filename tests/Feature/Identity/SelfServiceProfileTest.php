<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SelfServiceProfileTest extends TestCase
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

    public function test_identity_can_read_and_update_a_safe_profile(): void
    {
        $identity = UserIdentity::factory()->withPassword('profile-password')->create(['name' => 'Old Name']);
        $token = $this->login($identity, 'profile-password');

        $this->withToken($token)->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $identity->public_id)
            ->assertJsonPath('data.name', 'Old Name')
            ->assertJsonMissingPath('data.contacts.0.value');

        $this->withToken($token)->putJson('/api/v1/me/profile', [
            'name' => 'Updated Name',
            'user_id' => 999999,
            'verified_at' => now()->toISOString(),
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        self::assertSame('Updated Name', $identity->refresh()->name);
        self::assertDatabaseMissing('users', ['name' => 'Old Name']);
    }

    public function test_notification_preferences_are_server_keyed_and_idempotent(): void
    {
        $identity = UserIdentity::factory()->withPassword('preference-password')->create();
        $token = $this->login($identity, 'preference-password');

        $this->withToken($token)->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.preferences.finance_reminders', false)
            ->assertJsonMissingPath('data.preferences.unknown');

        $payload = ['preferences' => ['finance_reminders' => true, 'academic_updates' => true, 'unknown' => true, 'actor_id' => 99]];
        $this->withToken($token)->putJson('/api/v1/me/notification-preferences', $payload)
            ->assertOk()
            ->assertJsonPath('data.preferences.finance_reminders', true)
            ->assertJsonPath('data.preferences.academic_updates', true)
            ->assertJsonPath('data.preferences.attendance_updates', false)
            ->assertJsonMissingPath('data.preferences.unknown');

        $this->withToken($token)->putJson('/api/v1/me/notification-preferences', $payload)
            ->assertOk();

        self::assertDatabaseCount('user_notification_preferences', 1);
        self::assertDatabaseHas('user_notification_preferences', ['user_id' => $identity->id]);
        self::assertDatabaseHas('audit_events', ['action' => 'identity.notification_preferences_updated']);
    }

    public function test_revoked_session_cannot_read_or_update_self_service_data(): void
    {
        $identity = UserIdentity::factory()->withPassword('revoked-password')->create();
        $token = $this->login($identity, 'revoked-password');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me/profile')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/me/notification-preferences')->assertUnauthorized();
    }

    private function login(UserIdentity $identity, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $identity->contacts()->firstOrFail()->canonical_value,
            'password' => $password,
        ])->assertOk()->json('data.access_token');
    }
}
