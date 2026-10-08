<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Contexts\Platform\Domain\Models\IdempotencyRecord;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RegistrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_and_malformed_idempotency_keys_are_rejected(): void
    {
        $payload = $this->payload();

        $this->postJson('/api/v1/public/school-registrations', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.Idempotency-Key.0', 'The Idempotency-Key header must be 16 to 128 safe characters.');

        $this->withHeaders(['Idempotency-Key' => 'bad key'])
            ->postJson('/api/v1/public/school-registrations', $payload)
            ->assertUnprocessable();
    }

    public function test_replaying_the_same_key_and_payload_returns_the_same_registration(): void
    {
        $payload = $this->payload();
        $headers = ['Idempotency-Key' => 'registration-retry-001'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/public/school-registrations', $payload);
        $replay = $this->withHeaders($headers)->postJson('/api/v1/public/school-registrations', $payload);

        $first->assertAccepted();
        $replay->assertAccepted();
        self::assertSame($first->json('data.registration_id'), $replay->json('data.registration_id'));
        $this->assertDatabaseCount('school_registrations', 1);
        $this->assertDatabaseHas('idempotency_records', ['replay_count' => 1]);
    }

    public function test_reusing_a_key_with_a_different_payload_returns_a_generic_conflict(): void
    {
        $headers = ['Idempotency-Key' => 'registration-retry-002'];

        $this->withHeaders($headers)
            ->postJson('/api/v1/public/school-registrations', $this->payload())
            ->assertAccepted();

        $this->withHeaders($headers)
            ->postJson('/api/v1/public/school-registrations', $this->payload(['school_name' => 'Another Fictional Academy']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('error.details', [])
            ->assertJsonMissing(['registration_id' => true]);

        $this->assertDatabaseCount('school_registrations', 1);
    }

    public function test_different_keys_for_the_same_normalized_contact_return_the_existing_registration(): void
    {
        $first = $this->withHeaders(['Idempotency-Key' => 'registration-contact-001'])
            ->postJson('/api/v1/public/school-registrations', $this->payload(['contact' => 'Owner@Example.com']));
        $second = $this->withHeaders(['Idempotency-Key' => 'registration-contact-002'])
            ->postJson('/api/v1/public/school-registrations', $this->payload(['contact' => ' owner@example.com ']));

        $first->assertAccepted();
        $second->assertAccepted();
        self::assertSame($first->json('data.registration_id'), $second->json('data.registration_id'));
        $this->assertDatabaseCount('school_registrations', 1);
        $this->assertDatabaseCount('idempotency_records', 2);
    }

    public function test_phone_formatting_does_not_bypass_duplicate_detection(): void
    {
        $first = $this->withHeaders(['Idempotency-Key' => 'registration-phone-001'])
            ->postJson('/api/v1/public/school-registrations', $this->payload(['contact' => '+234 801 234 5678']));
        $second = $this->withHeaders(['Idempotency-Key' => 'registration-phone-002'])
            ->postJson('/api/v1/public/school-registrations', $this->payload(['contact' => '+2348012345678']));

        self::assertSame($first->json('data.registration_id'), $second->json('data.registration_id'));
        $this->assertDatabaseCount('school_registrations', 1);
    }

    public function test_cancelled_registrations_can_be_replaced(): void
    {
        $first = $this->withHeaders(['Idempotency-Key' => 'registration-cancel-001'])
            ->postJson('/api/v1/public/school-registrations', $this->payload());
        $registrationId = $first->json('data.registration_id');

        SchoolRegistration::query()->where('public_id', $registrationId)->update(['status' => 'cancelled']);

        $second = $this->withHeaders(['Idempotency-Key' => 'registration-cancel-002'])
            ->postJson('/api/v1/public/school-registrations', $this->payload());

        $second->assertAccepted();
        self::assertNotSame($registrationId, $second->json('data.registration_id'));
        $this->assertDatabaseCount('school_registrations', 2);
    }

    public function test_idempotency_records_store_only_derived_values_and_are_prunable(): void
    {
        $rawKey = 'registration-secret-key-001';
        $rawContact = 'owner@example.com';

        $this->withHeaders(['Idempotency-Key' => $rawKey])
            ->postJson('/api/v1/public/school-registrations', $this->payload(['contact' => $rawContact]))
            ->assertAccepted();

        $record = IdempotencyRecord::query()->firstOrFail();
        self::assertNotSame($rawKey, $record->key_hash);
        self::assertNotSame($rawContact, $record->fingerprint_hash);
        self::assertStringNotContainsString($rawKey, json_encode($record->getAttributes()));
        self::assertStringNotContainsString($rawContact, json_encode($record->getAttributes()));

        $record->registration()->update(['status' => 'completed']);
        $this->artisan('registrations:prune-idempotency')->assertExitCode(0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_registration_idempotency_cleanup_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('registrations:prune-idempotency')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'school_name' => 'Fictional Academy',
            'school_type' => 'secondary',
            'state' => 'Lagos',
            'contact' => 'owner@example.com',
            'consent_version' => 'v1',
        ], $overrides);
    }
}
