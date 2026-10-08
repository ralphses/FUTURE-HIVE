<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Application\Contracts\ContactVerificationCodeDelivery;
use App\Contexts\Identity\Domain\Models\ContactVerificationChallenge;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeContactVerificationCodeDelivery;
use Tests\TestCase;

final class ContactVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_request_is_generic_and_delivers_a_hashed_challenge(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update(['canonical_value' => 'verify@example.com']);
        $fake = $this->fakeDelivery();

        $known = $this->postJson('/api/v1/auth/verification/request', ['contact' => ' VERIFY@EXAMPLE.COM ']);
        $unknown = $this->postJson('/api/v1/auth/verification/request', ['contact' => 'missing@example.com']);

        $known->assertAccepted();
        $unknown->assertAccepted();
        self::assertSame($known->json('data.message'), $unknown->json('data.message'));
        self::assertSame('verify@example.com', $fake->contact);
        $challenge = ContactVerificationChallenge::query()->firstOrFail();
        self::assertNotSame($fake->code, $challenge->getRawOriginal('code_hash'));
        self::assertNull($challenge->getAttribute('code'));
    }

    public function test_phone_request_canonicalizes_international_input(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update([
            'type' => 'phone',
            'canonical_value' => '+2348031234567',
        ]);
        $fake = $this->fakeDelivery();

        $this->postJson('/api/v1/auth/verification/request', ['contact' => '+234 803 123 4567'])
            ->assertAccepted();

        self::assertSame('+2348031234567', $fake->contact);
    }

    public function test_already_verified_contact_has_the_same_generic_request_response_without_a_new_challenge(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update([
            'canonical_value' => 'verified@example.com',
            'verified_at' => now(),
        ]);
        $this->fakeDelivery();

        $this->postJson('/api/v1/auth/verification/request', ['contact' => 'verified@example.com'])
            ->assertAccepted();

        $this->assertDatabaseCount('contact_verification_challenges', 0);
    }

    public function test_confirmation_verifies_only_the_bound_contact_without_logging_in(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update(['canonical_value' => 'test@example.com']);
        $fake = $this->fakeDelivery();
        $this->postJson('/api/v1/auth/verification/request', ['contact' => 'test@example.com'])
            ->assertAccepted();

        $this->postJson('/api/v1/auth/verification/confirm', [
            'contact' => 'test@example.com',
            'code' => $fake->code,
        ])->assertOk()->assertJsonPath('data.verified', true);

        self::assertNotNull($identity->contacts()->firstOrFail()->fresh()->verified_at);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        self::assertNotNull(ContactVerificationChallenge::query()->firstOrFail()->fresh()->consumed_at);
    }

    public function test_confirmation_rejects_replay_expiry_and_exhaustion(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update(['canonical_value' => 'test@example.com']);
        $fake = $this->fakeDelivery();
        $this->requestVerification($fake);
        $challenge = ContactVerificationChallenge::query()->firstOrFail();

        $this->confirmVerification($fake->code)->assertOk();
        $this->confirmVerification($fake->code)->assertStatus(400)->assertJsonPath('error.code', 'VERIFICATION_FAILED');

        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update(['canonical_value' => 'second@example.com']);
        $this->requestVerification($fake, 'second@example.com');
        $expired = ContactVerificationChallenge::query()->latest('id')->firstOrFail();
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->confirmVerification($fake->code, 'second@example.com')->assertStatus(400);

        $identity->contacts()->update(['canonical_value' => 'third@example.com']);
        $this->requestVerification($fake, 'third@example.com');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->confirmVerification('000000', 'third@example.com');
        }

        $this->assertDatabaseHas('contact_verification_challenges', [
            'revoked_reason' => 'attempt_limit',
        ]);
        self::assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_new_request_revokes_the_previous_active_challenge(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update(['canonical_value' => 'test@example.com']);
        $fake = $this->fakeDelivery();
        $this->requestVerification($fake);
        $first = ContactVerificationChallenge::query()->firstOrFail();
        $this->requestVerification($fake);

        $this->assertDatabaseHas('contact_verification_challenges', [
            'id' => $first->id,
            'revoked_reason' => 'replaced',
        ]);
    }

    public function test_a_code_cannot_verify_a_different_contact(): void
    {
        $first = UserIdentity::factory()->create();
        $first->contacts()->update(['canonical_value' => 'first@example.com']);
        $second = UserIdentity::factory()->create();
        $second->contacts()->update(['canonical_value' => 'second@example.com']);
        $fake = $this->fakeDelivery();

        $this->requestVerification($fake, 'first@example.com');

        $this->confirmVerification($fake->code, 'second@example.com')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VERIFICATION_FAILED');

        self::assertNull($second->contacts()->firstOrFail()->fresh()->verified_at);
    }

    public function test_verification_requests_are_rate_limited(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update(['canonical_value' => 'limited@example.com']);
        $this->fakeDelivery();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/auth/verification/request', ['contact' => 'limited@example.com'])
                ->assertAccepted();
        }

        $this->postJson('/api/v1/auth/verification/request', ['contact' => 'limited@example.com'])
            ->assertTooManyRequests();
    }

    public function test_delivery_unavailability_fails_closed_without_challenge_reuse(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->update(['canonical_value' => 'test@example.com']);

        $this->postJson('/api/v1/auth/verification/request', ['contact' => 'test@example.com'])
            ->assertAccepted();

        $this->assertDatabaseHas('contact_verification_challenges', [
            'revoked_reason' => 'delivery_unavailable',
        ]);
    }

    private function fakeDelivery(): FakeContactVerificationCodeDelivery
    {
        $fake = new FakeContactVerificationCodeDelivery;
        $this->app->instance(ContactVerificationCodeDelivery::class, $fake);

        return $fake;
    }

    private function requestVerification(FakeContactVerificationCodeDelivery $fake, string $contact = 'test@example.com'): void
    {
        $this->postJson('/api/v1/auth/verification/request', ['contact' => $contact])->assertAccepted();
        self::assertNotSame('', $fake->code);
    }

    /** @return TestResponse<JsonResponse> */
    private function confirmVerification(string $code, string $contact = 'test@example.com'): TestResponse
    {
        return $this->postJson('/api/v1/auth/verification/confirm', [
            'contact' => $contact,
            'code' => $code,
        ]);
    }
}
