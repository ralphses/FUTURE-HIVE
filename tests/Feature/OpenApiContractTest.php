<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class OpenApiContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $contents = file_get_contents(base_path('openapi/openapi.json'));

        $this->assertIsString($contents);

        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($document);

        return $document;
    }

    public function test_generated_document_declares_openapi_31_and_the_implemented_routes(): void
    {
        $document = $this->document();

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame([
            '/auth/login',
            '/auth/refresh',
            '/auth/me',
            '/auth/logout',
            '/auth/logout-all',
            '/files/download',
            '/auth/verification/request',
            '/auth/verification/confirm',
            '/health',
            '/auth/password/forgot',
            '/auth/password/reset',
            '/auth/password/change',
            '/health/readiness',
            '/auth/context/switch',
            '/auth/context',
            '/schools/{school}/invitations',
            '/invitations/{invitation}/accept',
            '/invitations/{invitation}/revoke',
            '/me/memberships',
            '/public/school-registrations',
            '/public/school-registrations/{registration}/verification/request',
            '/public/school-registrations/{registration}/verification/confirm',
            '/schools/{school}/roles',
            '/schools/{school}/memberships/{membership}/roles',
            '/schools/{school}/memberships/{membership}/roles/{role}',
            '/me/schools/{school}/permissions',
        ], array_keys($document['paths']));
        $this->assertArrayNotHasKey('/students', $document['paths']);
    }

    public function test_standard_envelopes_headers_errors_and_authentication_are_documented(): void
    {
        $document = $this->document();

        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes']);
        $this->assertArrayHasKey('ApiError', $document['components']['schemas']);
        foreach (['/auth/me', '/auth/logout', '/auth/logout-all', '/auth/context/switch', '/auth/context', '/auth/password/change', '/schools/{school}/invitations', '/invitations/{invitation}/accept', '/invitations/{invitation}/revoke', '/me/memberships', '/schools/{school}/roles', '/schools/{school}/memberships/{membership}/roles', '/schools/{school}/memberships/{membership}/roles/{role}', '/me/schools/{school}/permissions'] as $protectedPath) {
            $this->assertSame([['bearerAuth' => []]], $document['paths'][$protectedPath][array_key_first($document['paths'][$protectedPath])]['security']);
        }

        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                $this->assertArrayHasKey('responses', $operation);
                $responseForHeader = $operation['responses']['200'] ?? $operation['responses']['201'] ?? $operation['responses']['202'] ?? $operation['responses']['302'] ?? [];
                $this->assertArrayHasKey('X-Request-ID', $responseForHeader['headers'] ?? []);

                foreach ([401, 403, 404, 405, 422, 500, 503] as $status) {
                    $this->assertArrayHasKey((string) $status, $operation['responses']);
                    $this->assertSame(
                        '#/components/schemas/ApiError',
                        $operation['responses'][(string) $status]['content']['application/json']['schema']['properties']['error']['$ref'],
                    );
                }
            }
        }

        $parameters = array_column($document['paths']['/files/download']['get']['parameters'], 'name');
        $this->assertContains('school_id', $parameters);
        $this->assertContains('public_id', $parameters);
        $this->assertArrayHasKey('302', $document['paths']['/files/download']['get']['responses']);

        $registrationParameters = array_column($document['paths']['/public/school-registrations']['post']['parameters'], 'name');
        $this->assertContains('Idempotency-Key', $registrationParameters);
        $this->assertArrayHasKey('409', $document['paths']['/public/school-registrations']['post']['responses']);

        $this->assertSame([], $document['paths']['/public/school-registrations/{registration}/verification/request']['post']['security'] ?? []);
        $this->assertSame([], $document['paths']['/public/school-registrations/{registration}/verification/confirm']['post']['security'] ?? []);
        $this->assertTrue(
            isset($document['paths']['/public/school-registrations/{registration}/verification/request']['post']['responses']['200'])
                || isset($document['paths']['/public/school-registrations/{registration}/verification/request']['post']['responses']['202']),
        );
        $this->assertArrayHasKey('400', $document['paths']['/public/school-registrations/{registration}/verification/confirm']['post']['responses']);
    }

    public function test_operations_use_only_the_bounded_context_documentation_groups(): void
    {
        $document = $this->document();
        $expectedTags = ['Platform', 'Identity & Authentication', 'School Access', 'School Registration'];

        $this->assertSame($expectedTags, array_column($document['tags'], 'name'));

        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                $this->assertCount(1, $operation['tags']);
                $this->assertContains($operation['tags'][0], $expectedTags);
            }
        }

        $contents = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['Authentication', 'SchoolInvitation', 'SchoolMembership', 'SchoolRole', 'Health', 'Readiness'] as $controllerTag) {
            self::assertStringNotContainsString('"'.$controllerTag.'"', $contents);
        }
    }

    public function test_documentation_contains_no_deployment_secrets_or_connection_strings(): void
    {
        $contents = json_encode($this->document(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        foreach (['CLOUDINARY_URL', 'api_secret', 'postgres://', 'redis://', 'password='] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $contents);
        }
    }

    public function test_generated_artifact_passes_the_strict_drift_check(): void
    {
        $exitCode = Artisan::call('openapi:check');

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function test_documentation_routes_are_denied_by_default_and_available_when_enabled(): void
    {
        $this->get('/docs/api.json')->assertForbidden();

        config(['scramble.docs_enabled' => true]);

        $this->get('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0');

        $this->get('/docs/api')->assertOk();
    }
}
