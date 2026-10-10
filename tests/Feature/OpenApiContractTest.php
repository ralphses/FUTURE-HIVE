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
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/activate',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/retire',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-scheme',
            '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms',
            '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}',
            '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}/activate',
            '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}/deactivate',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}/activate',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}/retire',
            '/schools/{school}/academic-sessions',
            '/schools/{school}/academic-sessions/{session}',
            '/schools/{school}/academic-sessions/{session}/activate',
            '/schools/{school}/academic-sessions/{session}/close',
            '/schools/{school}/academic-sessions/{session}/terms',
            '/schools/{school}/academic-sessions/{session}/terms/{term}',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/activate',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/close',
            '/schools/{school}/academic-context',
            '/schools/{school}/promotion-rules',
            '/schools/{school}/promotion-rules/{rule}',
            '/schools/{school}/promotion-rules/{rule}/activate',
            '/schools/{school}/promotion-rules/{rule}/deactivate',
            '/schools/{school}/academic-readiness',
            '/schools/{school}/academic-levels',
            '/schools/{school}/academic-levels/{level}',
            '/schools/{school}/academic-levels/{level}/activate',
            '/schools/{school}/academic-levels/{level}/deactivate',
            '/schools/{school}/academic-levels/{level}/sections',
            '/schools/{school}/academic-levels/{level}/sections/{section}',
            '/schools/{school}/academic-levels/{level}/sections/{section}/activate',
            '/schools/{school}/academic-levels/{level}/sections/{section}/deactivate',
            '/schools/{school}/subjects',
            '/schools/{school}/subjects/{subject}',
            '/schools/{school}/subjects/{subject}/activate',
            '/schools/{school}/subjects/{subject}/deactivate',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/activate',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/deactivate',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments/{assignment}',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments/{assignment}/revoke',
            '/auth/login',
            '/auth/refresh',
            '/auth/me',
            '/auth/logout',
            '/auth/logout-all',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/class-arms/{classArm}/class-teacher-assignments',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/class-arms/{classArm}/class-teacher-assignments/{assignment}',
            '/schools/{school}/academic-sessions/{session}/terms/{term}/class-arms/{classArm}/class-teacher-assignments/{assignment}/revoke',
            '/files/download',
            '/auth/verification/request',
            '/auth/verification/confirm',
            '/guardian-invitations/{invitation}/confirm',
            '/schools/{school}/students/{student}/guardian-relationships/{relationship}/invitation',
            '/me/guardian-links',
            '/schools/{school}/guardian-invitations/{invitation}/revoke',
            '/schools/{school}/guardians',
            '/schools/{school}/guardians/{guardian}',
            '/schools/{school}/students/{student}/guardian-relationships',
            '/schools/{school}/students/{student}/guardian-relationships/{relationship}',
            '/schools/{school}/students/{student}/guardian-relationships/{relationship}/revoke',
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
            '/schools/{school}/lifecycle',
            '/schools/{school}/lifecycle/suspend',
            '/schools/{school}/lifecycle/reactivate',
            '/schools/{school}/lifecycle/archive',
            '/me/memberships',
            '/schools/{school}/profile',
            '/schools/{school}/profile/logo',
            '/public/school-registrations',
            '/public/school-registrations/{registration}/verification/request',
            '/public/school-registrations/{registration}/verification/confirm',
            '/schools/{school}/roles',
            '/schools/{school}/memberships/{membership}/roles',
            '/schools/{school}/memberships/{membership}/roles/{role}',
            '/me/schools/{school}/permissions',
            '/schools/{school}/setup',
            '/schools/{school}/setup/{item}',
            '/schools/{school}/staff',
            '/schools/{school}/staff/{staff}',
            '/schools/{school}/staff/{staff}/activate',
            '/schools/{school}/staff/{staff}/suspend',
            '/schools/{school}/staff/{staff}/end',
            '/schools/{school}/students',
            '/schools/{school}/students/{student}',
            '/schools/{school}/students/{student}/activate',
            '/schools/{school}/students/{student}/withdraw',
            '/schools/{school}/students/{student}/enrollments',
            '/schools/{school}/students/{student}/enrollments/{enrollment}',
            '/schools/{school}/students/{student}/enrollments/{enrollment}/end',
            '/schools/{school}/students/{student}/enrollments/{enrollment}/transfer',
            '/schools/{school}/students/{student}/enrollments/{enrollment}/withdraw',
            '/schools/{school}/students/{student}/enrollment-history',
            '/schools/{school}/students/{student}/profile',
            '/schools/{school}/students/{student}/documents',
            '/schools/{school}/students/{student}/documents/{document}',
            '/schools/{school}/students/{student}/documents/{document}/revoke',
            '/schools/{school}/promotion-cycles',
            '/schools/{school}/promotion-cycles/{cycle}',
            '/schools/{school}/promotion-cycles/{cycle}/decisions',
            '/schools/{school}/promotion-cycles/{cycle}/approve',
            '/schools/{school}/promotion-cycles/{cycle}/apply',
            '/schools/{school}/promotion-cycles/{cycle}/rollback',
        ], array_keys($document['paths']));
        $this->assertArrayNotHasKey('/students', $document['paths']);
    }

    public function test_standard_envelopes_headers_errors_and_authentication_are_documented(): void
    {
        $document = $this->document();

        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes']);
        $this->assertArrayHasKey('ApiError', $document['components']['schemas']);
        foreach (['/auth/me', '/auth/logout', '/auth/context/switch', '/auth/context', '/auth/password/change', '/schools/{school}/invitations', '/invitations/{invitation}/accept', '/invitations/{invitation}/revoke', '/schools/{school}/lifecycle', '/schools/{school}/lifecycle/suspend', '/schools/{school}/lifecycle/reactivate', '/schools/{school}/lifecycle/archive', '/schools/{school}/academic-levels', '/schools/{school}/academic-levels/{level}', '/schools/{school}/academic-levels/{level}/sections', '/schools/{school}/academic-levels/{level}/sections/{section}', '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms', '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}', '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}/activate', '/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}/deactivate', '/schools/{school}/subjects', '/schools/{school}/subjects/{subject}', '/schools/{school}/subjects/{subject}/activate', '/schools/{school}/subjects/{subject}/deactivate', '/schools/{school}/academic-sessions', '/schools/{school}/academic-sessions/{session}', '/schools/{school}/academic-sessions/{session}/terms', '/schools/{school}/academic-sessions/{session}/terms/{term}', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/activate', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/deactivate', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments/{assignment}', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments/{assignment}/revoke', '/schools/{school}/academic-context', '/me/memberships', '/schools/{school}/roles', '/schools/{school}/memberships/{membership}/roles', '/schools/{school}/memberships/{membership}/roles/{role}', '/me/schools/{school}/permissions', '/schools/{school}/setup', '/schools/{school}/setup/{item}', '/schools/{school}/profile', '/schools/{school}/profile/logo', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}/activate', '/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}/retire'] as $protectedPath) {
            $this->assertSame([['bearerAuth' => []]], $document['paths'][$protectedPath][array_key_first($document['paths'][$protectedPath])]['security']);
        }

        foreach (['/schools/{school}/staff', '/schools/{school}/staff/{staff}', '/schools/{school}/staff/{staff}/activate', '/schools/{school}/staff/{staff}/suspend', '/schools/{school}/staff/{staff}/end'] as $staffPath) {
            $this->assertSame([['bearerAuth' => []]], $document['paths'][$staffPath][array_key_first($document['paths'][$staffPath])]['security']);
        }

        foreach (['/schools/{school}/academic-sessions/{session}/terms/{term}/class-arms/{classArm}/class-teacher-assignments', '/schools/{school}/academic-sessions/{session}/terms/{term}/class-arms/{classArm}/class-teacher-assignments/{assignment}', '/schools/{school}/academic-sessions/{session}/terms/{term}/class-arms/{classArm}/class-teacher-assignments/{assignment}/revoke'] as $classTeacherPath) {
            foreach ($document['paths'][$classTeacherPath] as $operation) {
                $this->assertSame([['bearerAuth' => []]], $operation['security']);
            }
        }

        $this->assertSame([['bearerAuth' => []]], $document['paths']['/schools/{school}/academic-readiness']['get']['security']);
        foreach (['/schools/{school}/students', '/schools/{school}/students/{student}', '/schools/{school}/students/{student}/activate', '/schools/{school}/students/{student}/withdraw', '/schools/{school}/students/{student}/profile', '/schools/{school}/students/{student}/documents', '/schools/{school}/students/{student}/documents/{document}', '/schools/{school}/students/{student}/documents/{document}/revoke', '/schools/{school}/guardians', '/schools/{school}/guardians/{guardian}', '/schools/{school}/students/{student}/guardian-relationships', '/schools/{school}/students/{student}/guardian-relationships/{relationship}', '/schools/{school}/students/{student}/guardian-relationships/{relationship}/revoke'] as $studentPath) {
            foreach ($document['paths'][$studentPath] as $operation) {
                $this->assertSame([['bearerAuth' => []]], $operation['security']);
            }
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
        $expectedTags = [
            'Platform',
            'Identity & Authentication',
            'School Registration',
            'School Context & Memberships',
            'School Administration',
            'Academic Structure',
            'Academic Assessment',
            'Student Registry',
        ];

        $this->assertSame($expectedTags, array_column($document['tags'], 'name'));

        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                $this->assertCount(1, $operation['tags']);
                $this->assertContains($operation['tags'][0], $expectedTags);
            }
        }

        $contents = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['School Access', 'Authentication', 'SchoolInvitation', 'SchoolMembership', 'SchoolRole', 'Health', 'Readiness'] as $controllerTag) {
            self::assertStringNotContainsString('"'.$controllerTag.'"', $contents);
        }
    }

    public function test_operations_and_inputs_have_plain_language_documentation(): void
    {
        $document = $this->document();

        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                self::assertNotSame('', trim((string) ($operation['summary'] ?? '')));
                self::assertNotSame('', trim((string) ($operation['description'] ?? '')));

                foreach ($operation['parameters'] ?? [] as $parameter) {
                    self::assertNotSame('', trim((string) ($parameter['description'] ?? '')));
                }

                if (isset($operation['requestBody'])) {
                    self::assertNotSame('', trim((string) ($operation['requestBody']['description'] ?? '')));
                    self::assertStringNotContainsString('Send the fields required for this operation.', $operation['requestBody']['description']);
                }
            }
        }
    }

    public function test_request_schema_fields_have_plain_language_descriptions_and_safe_examples(): void
    {
        $document = $this->document();

        foreach ($document['components']['schemas'] as $schemaName => $schema) {
            if (! str_ends_with($schemaName, 'Request')) {
                continue;
            }

            foreach ($schema['properties'] ?? [] as $property) {
                self::assertNotSame('', trim((string) ($property['description'] ?? '')), $schemaName);
                self::assertNotSame('', trim((string) ($property['x-field-label'] ?? '')), $schemaName);

                foreach ($property['examples'] ?? [] as $example) {
                    self::assertIsScalar($example);
                    self::assertDoesNotMatchRegularExpression('/password|secret|token|bearer|postgres|redis|cloudinary/i', (string) $example);
                }
            }
        }

        $contents = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('Input value for this operation.', $contents);
        self::assertStringNotContainsString('Server-managed ownership, actor, lifecycle and timestamp fields are not trusted.', $contents);
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
