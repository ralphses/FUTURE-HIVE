<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudinary.url' => 'cloudinary://fictional-key:fictional-secret@example',
        ]);
    }

    public function test_readiness_returns_safe_success_shape_when_dependencies_are_configured(): void
    {
        $response = $this->getJson('/api/v1/health/readiness');

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks.application', 'ok')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.storage', 'configured')
            ->assertJsonMissing(['fictional-secret'])
            ->assertHeader('X-Request-ID');
    }

    public function test_readiness_returns_generic_service_unavailable_error_when_storage_is_unconfigured(): void
    {
        config(['services.cloudinary.url' => null]);

        $response = $this->getJson('/api/v1/health/readiness');

        $response
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.message', 'The service is not ready.')
            ->assertJsonPath('error.details.checks.storage', 'unavailable')
            ->assertJsonStructure(['error' => ['details', 'request_id']])
            ->assertDontSee('cloudinary://')
            ->assertDontSee('fictional-secret');

        self::assertSame(
            $response->headers->get('X-Request-ID'),
            $response->json('error.request_id'),
        );
    }
}
