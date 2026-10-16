<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Platform\Domain\Models\PublicDemoEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicDemoEnquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_enquiry_is_accepted_and_idempotently_replayed_without_exposing_sensitive_values(): void
    {
        $payload = [
            'name' => 'Ada Example',
            'organization' => 'Example School',
            'role' => 'Principal',
            'contact' => ' ADA@EXAMPLE.TEST ',
            'category' => 'demo',
            'message' => 'Please arrange a product demonstration.',
            'consent_version' => 'marketing-v1',
        ];

        $first = $this->withHeader('Idempotency-Key', 'demo-enquiry-key-0001')
            ->postJson('/api/v1/public/demo-enquiries', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.accepted', true)
            ->assertJsonMissing(['contact' => 'ADA@EXAMPLE.TEST'])
            ->assertJsonMissing(['message' => $payload['message']]);

        $requestId = $first->json('data.request_id');
        $this->assertIsString($requestId);

        $enquiry = PublicDemoEnquiry::query()->firstOrFail();
        $this->assertSame('received', $enquiry->status);
        $this->assertNotSame('ADA@EXAMPLE.TEST', $enquiry->contact_hash);
        $this->assertSame($requestId, $enquiry->request_id);

        $this->withHeader('Idempotency-Key', 'demo-enquiry-key-0001')
            ->postJson('/api/v1/public/demo-enquiries', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.request_id', $requestId);

        $this->assertDatabaseCount('public_demo_enquiries', 1);
    }

    public function test_changed_payload_with_same_idempotency_key_is_rejected(): void
    {
        $headers = ['Idempotency-Key' => 'demo-enquiry-key-0002'];
        $payload = [
            'name' => 'Ada Example',
            'contact' => 'ada@example.test',
            'category' => 'contact',
            'message' => 'First message.',
            'consent_version' => 'marketing-v1',
        ];

        $this->withHeaders($headers)->postJson('/api/v1/public/demo-enquiries', $payload)->assertStatus(202);
        $this->withHeaders($headers)->postJson('/api/v1/public/demo-enquiries', [...$payload, 'message' => 'Changed message.'])->assertStatus(409);
        $this->assertDatabaseCount('public_demo_enquiries', 1);
    }

    public function test_validation_requires_consent_category_and_idempotency_key(): void
    {
        $this->postJson('/api/v1/public/demo-enquiries', [
            'name' => 'Ada Example',
            'contact' => 'ada@example.test',
            'category' => 'unknown',
            'message' => 'A message.',
        ])->assertStatus(422);
    }
}
