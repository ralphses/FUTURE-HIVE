<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Contexts\Platform\Application\Contracts\RegistrationVerificationCodeDelivery;
use App\Contexts\Platform\Domain\Models\RegistrationVerificationChallenge;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeRegistrationVerificationCodeDelivery;
use Tests\TestCase;

final class SchoolRegistrationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeRegistrationVerificationCodeDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->delivery = new FakeRegistrationVerificationCodeDelivery;
        $this->app->instance(RegistrationVerificationCodeDelivery::class, $this->delivery);
    }

    public function test_email_registration_can_be_verified_without_creating_identity_or_school_data(): void
    {
        $registration = $this->createRegistration('owner@example.com');

        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/request")
            ->assertAccepted()
            ->assertJsonPath('data.message', 'If the registration can be verified, a verification code will be delivered.');

        $this->assertSame('owner@example.com', $this->delivery->contact);
        self::assertMatchesRegularExpression('/^\d{6}$/', $this->delivery->code);

        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/confirm", [
            'code' => $this->delivery->code,
            'contact' => 'attacker@example.com',
        ])->assertOk()->assertJsonPath('data.verified', true)->assertJsonPath('data.status', 'verified');

        $this->assertDatabaseHas('school_registrations', [
            'id' => $registration->id,
            'status' => 'verified',
        ]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('schools', 0);
        $this->assertDatabaseCount('school_memberships', 0);
        $this->assertDatabaseCount('auth_sessions', 0);
    }

    public function test_international_phone_registration_uses_the_stored_contact(): void
    {
        $registration = $this->createRegistration('+234 801 234 5678');

        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/request")
            ->assertAccepted();

        self::assertSame('+2348012345678', $this->delivery->contact);
        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/confirm", [
            'code' => $this->delivery->code,
        ])->assertOk();
    }

    public function test_challenge_code_is_hashed_and_not_stored_in_plaintext(): void
    {
        $registration = $this->createRegistration('owner@example.com');
        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/request")->assertAccepted();

        $challenge = RegistrationVerificationChallenge::query()->firstOrFail();

        self::assertNotSame($this->delivery->code, $challenge->getRawOriginal('code_hash'));
        self::assertSame(hash('sha256', $this->delivery->code), $challenge->getRawOriginal('code_hash'));
        self::assertArrayNotHasKey('code', $challenge->getAttributes());
        self::assertArrayNotHasKey('canonical_contact', $challenge->getAttributes());
    }

    public function test_unknown_unavailable_and_already_verified_registrations_fail_safely(): void
    {
        $unknown = $this->postJson('/api/v1/public/school-registrations/'.Str::uuid7().'/verification/request');
        $unknown->assertAccepted();

        $this->postJson('/api/v1/public/school-registrations/'.Str::uuid7().'/verification/confirm', ['code' => '123456'])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'VERIFICATION_FAILED');

        $registration = $this->createRegistration('owner@example.com');
        $registration->update(['status' => 'cancelled']);
        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/request")
            ->assertAccepted();

        $verified = $this->createRegistration('verified@example.com');
        $verified->update(['status' => 'verified']);
        $this->postJson("/api/v1/public/school-registrations/{$verified->public_id}/verification/confirm", ['code' => '123456'])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'VERIFICATION_FAILED');
    }

    public function test_invalid_code_attempts_exhaust_the_challenge(): void
    {
        $registration = $this->createRegistration('owner@example.com');
        $this->requestVerification($registration);

        foreach (range(1, 5) as $attempt) {
            $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/confirm", ['code' => '000000'])
                ->assertBadRequest();
        }

        $challenge = RegistrationVerificationChallenge::query()->firstOrFail();
        self::assertSame(5, $challenge->attempts);
        self::assertNotNull($challenge->revoked_at);
        $this->assertDatabaseHas('school_registrations', ['id' => $registration->id, 'status' => 'pending_verification']);
    }

    public function test_new_request_revokes_previous_challenge_and_delivery_failure_fails_closed(): void
    {
        $registration = $this->createRegistration('owner@example.com');
        $this->requestVerification($registration);
        $first = RegistrationVerificationChallenge::query()->firstOrFail();
        $firstCode = $this->delivery->code;

        $this->requestVerification($registration);
        $first->refresh();
        self::assertNotNull($first->revoked_at);
        self::assertNotSame($firstCode, $this->delivery->code);

        $this->delivery->shouldFail = true;
        $secondCode = $this->delivery->code;
        $this->requestVerification($registration);
        self::assertSame($secondCode, $this->delivery->code);
        self::assertSame(3, RegistrationVerificationChallenge::query()->count());
        self::assertSame(3, RegistrationVerificationChallenge::query()->whereNotNull('revoked_at')->count());
        $this->assertDatabaseHas('school_registrations', ['id' => $registration->id, 'status' => 'pending_verification']);
    }

    public function test_expired_and_malformed_codes_fail_without_verifying(): void
    {
        $registration = $this->createRegistration('owner@example.com');
        $this->requestVerification($registration);
        RegistrationVerificationChallenge::query()->firstOrFail()->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/confirm", ['code' => '12345'])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'VERIFICATION_FAILED');
        $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/confirm", ['code' => '123456'])
            ->assertBadRequest();
        $this->assertDatabaseHas('school_registrations', ['id' => $registration->id, 'status' => 'pending_verification']);
    }

    public function test_verification_request_is_rate_limited(): void
    {
        $registration = $this->createRegistration('owner@example.com');

        $this->requestVerification($registration)->assertAccepted();
        $this->requestVerification($registration)->assertAccepted();
        $this->requestVerification($registration)->assertAccepted();
        $this->requestVerification($registration)->assertTooManyRequests();
    }

    private function createRegistration(string $contact): SchoolRegistration
    {
        $response = $this->withHeaders(['Idempotency-Key' => Str::random(24)])->postJson('/api/v1/public/school-registrations', [
            'school_name' => 'Fictional Academy',
            'school_type' => 'secondary',
            'state' => 'Lagos',
            'contact' => $contact,
            'consent_version' => 'v1',
        ]);

        $response->assertAccepted();

        return SchoolRegistration::query()->where('public_id', $response->json('data.registration_id'))->firstOrFail();
    }

    /** @return TestResponse<JsonResponse> */
    private function requestVerification(SchoolRegistration $registration): TestResponse
    {
        return $this->postJson("/api/v1/public/school-registrations/{$registration->public_id}/verification/request");
    }
}
