<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SchoolRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_email_intake_creates_only_a_pending_registration(): void
    {
        $response = $this->submit([
            'school_name' => 'Fictional Academy',
            'school_type' => 'secondary',
            'state' => 'Lagos',
            'contact' => 'Owner@Example.com',
            'consent_version' => 'v1',
        ]);

        $response->assertAccepted()
            ->assertJsonStructure(['data' => ['registration_id', 'status']])
            ->assertJsonPath('data.status', 'pending_verification');
        $this->assertNull($response->json('data.contact'));
        $this->assertNull($response->json('data.internal_id'));

        $this->assertDatabaseHas('school_registrations', [
            'school_name' => 'Fictional Academy',
            'contact_type' => 'email',
            'canonical_contact' => 'owner@example.com',
            'status' => 'pending_verification',
        ]);
        $this->assertDatabaseCount('schools', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('school_memberships', 0);
        $this->assertDatabaseCount('auth_sessions', 0);
        self::assertTrue(Str::isUuid($response->json('data.registration_id')));
        self::assertSame('7', substr((string) $response->json('data.registration_id'), 14, 1));
    }

    public function test_international_phone_is_stored_as_e164(): void
    {
        $this->submit([
            'school_name' => 'Fictional College',
            'school_type' => 'college',
            'state' => 'Abuja',
            'contact' => '+234 801 234 5678',
            'consent_version' => 'v1',
        ])->assertAccepted();

        $this->assertDatabaseHas('school_registrations', [
            'contact_type' => 'phone',
            'canonical_contact' => '+2348012345678',
        ]);
    }

    public function test_local_and_malformed_phone_contacts_are_rejected(): void
    {
        foreach (['08012345678', 'not-a-contact'] as $contact) {
            $this->submit([
                'school_name' => 'Fictional Academy',
                'school_type' => 'secondary',
                'state' => 'Lagos',
                'contact' => $contact,
                'consent_version' => 'v1',
            ])->assertUnprocessable()->assertJsonPath('error.details.contact.0', 'The contact is invalid.');
        }
    }

    public function test_required_fields_and_client_controlled_fields_are_rejected(): void
    {
        $this->submit([
            'status' => 'completed',
            'school_id' => 123,
            'user_id' => 456,
            'consented_at' => '2026-01-01T00:00:00Z',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.school_name.0', 'The school name field is required.');
    }

    public function test_existing_and_unknown_contacts_receive_the_same_safe_intake_shape(): void
    {
        $identity = UserIdentity::factory()->create();
        $identity->contacts()->create([
            'type' => ContactType::Email,
            'canonical_value' => 'owner@example.com',
            'is_primary' => true,
        ]);

        $payload = [
            'school_name' => 'Fictional Academy',
            'school_type' => 'secondary',
            'state' => 'Lagos',
            'consent_version' => 'v1',
        ];
        $known = $this->submit($payload + ['contact' => 'owner@example.com']);
        $unknown = $this->submit($payload + ['contact' => 'other@example.com']);

        $known->assertAccepted();
        $unknown->assertAccepted();
        self::assertSame(['registration_id', 'status'], array_keys($known->json('data')));
        self::assertSame(['registration_id', 'status'], array_keys($unknown->json('data')));
        self::assertSame('pending_verification', $known->json('data.status'));
        self::assertSame('pending_verification', $unknown->json('data.status'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_contact_and_consent_are_not_logged_or_returned(): void
    {
        $response = $this->submit([
            'school_name' => 'Fictional Academy',
            'school_type' => 'secondary',
            'state' => 'Lagos',
            'contact' => 'secret-owner@example.com',
            'consent_version' => 'private-consent-v1',
        ]);

        $response->assertAccepted();
        self::assertStringNotContainsString('secret-owner@example.com', $response->getContent());
        self::assertStringNotContainsString('private-consent-v1', $response->getContent());
    }

    public function test_public_registration_is_rate_limited_without_authentication(): void
    {
        $payload = [
            'school_name' => 'Fictional Academy',
            'school_type' => 'secondary',
            'state' => 'Lagos',
            'contact' => 'rate-limited@example.com',
            'consent_version' => 'v1',
        ];

        $this->submit($payload)->assertAccepted();
        $this->submit($payload)->assertAccepted();
        $this->submit($payload)->assertAccepted();
        $this->submit($payload)->assertTooManyRequests();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<JsonResponse>
     */
    private function submit(array $payload): TestResponse
    {
        return $this->withHeaders(['Idempotency-Key' => Str::random(24)])
            ->postJson('/api/v1/public/school-registrations', $payload);
    }
}
