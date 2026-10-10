<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class FoundationContractDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $components = $document->components;
        $this->addBoundedContextTags($document);
        $components->addSchema('ApiError', $this->errorType());
        $components->addSecurityScheme(
            'bearerAuth',
            SecurityScheme::http('bearer', 'JWT')
                ->as('bearerAuth')
                ->setDescription('JWT access-token authentication using the approved RS256 issuer and rotating key IDs.'),
        );
        $this->addRequestSchemaDocumentation($components);

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $operation->setTags([$this->tagForOperation($operation->operationId)]);
                $this->addPlainLanguageDocumentation($operation);
                $this->addRequestIdHeaders($operation);
                $this->addStandardErrorResponses($operation, $components);

                if (in_array($operation->operationId, [
                    'v1.auth.me',
                    'v1.auth.logout',
                    'v1.auth.logout_all',
                    'v1.auth.password.change',
                    'v1.me.memberships',
                    'v1.schools.invitations.create',
                    'v1.invitations.accept',
                    'v1.invitations.revoke',
                    'v1.schools.roles.catalogue',
                    'v1.schools.memberships.roles',
                    'v1.schools.memberships.roles.assign',
                    'v1.schools.memberships.roles.revoke',
                    'v1.me.schools.permissions',
                    'v1.schools.setup.index',
                    'v1.schools.setup.update',
                    'v1.schools.profile.show',
                    'v1.schools.profile.update',
                    'v1.schools.profile.logo.upload',
                    'v1.schools.profile.logo.remove',
                    'v1.schools.lifecycle.show',
                    'v1.schools.lifecycle.suspend',
                    'v1.schools.lifecycle.reactivate',
                    'v1.schools.lifecycle.archive',
                    'v1.schools.academic-sessions.index',
                    'v1.schools.academic-sessions.store',
                    'v1.schools.academic-sessions.show',
                    'v1.schools.academic-sessions.update',
                    'v1.schools.academic-sessions.activate',
                    'v1.schools.academic-sessions.close',
                    'v1.schools.academic-sessions.terms.index',
                    'v1.schools.academic-sessions.terms.store',
                    'v1.schools.academic-sessions.terms.update',
                    'v1.schools.academic-sessions.terms.activate',
                    'v1.schools.academic-sessions.terms.close',
                    'v1.schools.academic-sessions.terms.subject-offerings.index',
                    'v1.schools.academic-sessions.terms.subject-offerings.store',
                    'v1.schools.academic-sessions.terms.subject-offerings.show',
                    'v1.schools.academic-sessions.terms.subject-offerings.update',
                    'v1.schools.academic-sessions.terms.subject-offerings.activate',
                    'v1.schools.academic-sessions.terms.subject-offerings.deactivate',
                    'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.index',
                    'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.store',
                    'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.show',
                    'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.update',
                    'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.revoke',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.show',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.update',
                    'v1.schools.academic-context',
                    'v1.schools.academic-levels.index',
                    'v1.schools.academic-levels.store',
                    'v1.schools.academic-levels.show',
                    'v1.schools.academic-levels.update',
                    'v1.schools.academic-levels.activate',
                    'v1.schools.academic-levels.deactivate',
                    'v1.schools.academic-levels.sections.index',
                    'v1.schools.academic-levels.sections.store',
                    'v1.schools.academic-levels.sections.show',
                    'v1.schools.academic-levels.sections.update',
                    'v1.schools.academic-levels.sections.activate',
                    'v1.schools.academic-levels.sections.deactivate',
                    'v1.schools.academic-levels.sections.class-arms.index',
                    'v1.schools.academic-levels.sections.class-arms.store',
                    'v1.schools.academic-levels.sections.class-arms.show',
                    'v1.schools.academic-levels.sections.class-arms.update',
                    'v1.schools.academic-levels.sections.class-arms.activate',
                    'v1.schools.academic-levels.sections.class-arms.deactivate',
                    'v1.schools.subjects.index',
                    'v1.schools.subjects.store',
                    'v1.schools.subjects.show',
                    'v1.schools.subjects.update',
                    'v1.schools.subjects.activate',
                    'v1.schools.subjects.deactivate',
                    'v1.auth.context.switch',
                    'v1.auth.context',
                    'v1.schools.roles.catalogue',
                    'v1.schools.memberships.roles',
                    'v1.schools.memberships.roles.assign',
                    'v1.schools.memberships.roles.revoke',
                    'v1.me.schools.permissions',
                ], true)) {
                    $operation->addSecurity(new SecurityRequirement(['bearerAuth' => []]));
                }
            }
        }
    }

    private function addBoundedContextTags(OpenApi $document): void
    {
        $document->tags = [
            new Tag('Platform', 'Infrastructure health, readiness and private file delivery.'),
            new Tag('Identity & Authentication', 'Global identity authentication, credentials and contact verification.'),
            new Tag('School Access', 'Authenticated school context, memberships, invitations, roles and permissions.'),
            new Tag('School Registration', 'Unauthenticated provisional registration and registration-bound verification.'),
        ];
    }

    private function addPlainLanguageDocumentation(Operation $operation): void
    {
        [$summary, $description] = $this->operationDocumentation($operation->operationId);
        $operation->summary($summary)->description($description);

        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof Parameter) {
                $parameter->description($this->parameterDescription($parameter->name, $parameter->in));

                if ($parameter->name === 'school') {
                    $parameter->example('0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1001');
                } elseif ($parameter->name === 'session') {
                    $parameter->example('0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1002');
                } elseif ($parameter->name === 'term') {
                    $parameter->example('0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1003');
                } elseif ($parameter->name === 'offering') {
                    $parameter->example('0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1004');
                } elseif ($parameter->name === 'assignment') {
                    $parameter->example('0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1005');
                } elseif ($parameter->name === 'membership') {
                    $parameter->example('0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1006');
                } elseif ($parameter->name === 'registration') {
                    $parameter->example('0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1007');
                } elseif ($parameter->name === 'item') {
                    $parameter->example('school_details');
                } elseif ($parameter->name === 'Idempotency-Key') {
                    $parameter->example('school-intake-demo-001');
                }
            }
        }

        if ($operation->requestBodyObject !== null) {
            $operation->requestBodyObject->description($this->requestBodyDescription($operation->operationId));
        }
    }

    /** @return array{0: string, 1: string} */
    private function operationDocumentation(?string $operationId): array
    {
        $fixed = [
            'v1.health' => ['Check API health', 'Confirms that the application process is running. This liveness check does not contact external services.'],
            'v1.health.readiness' => ['Check service readiness', 'Checks the configured database, cache, queue and file-storage dependencies without exposing credentials or connection details.'],
            'v1.files.download' => ['Download a private school file', 'Returns a short-lived signed redirect for a school file after trusted school authorization. Unsigned provider URLs are never returned.'],
            'v1.auth.login' => ['Sign in', 'Authenticates an identity with a verified email or phone contact and password, then returns a short-lived access token and session details.'],
            'v1.auth.refresh' => ['Refresh an access token', 'Rotates the refresh token and returns a new access token. The previous refresh token cannot be reused.'],
            'v1.auth.me' => ['Get the signed-in identity', 'Returns the authenticated identity and its active contact records.'],
            'v1.auth.logout' => ['Sign out this session', 'Revokes the current authentication session.'],
            'v1.auth.logout_all' => ['Sign out all sessions', 'Revokes every active authentication session for the signed-in identity.'],
            'v1.auth.password.forgot' => ['Request a password reset', 'Starts a generic password-recovery request. The response does not reveal whether the contact exists or whether delivery is available.'],
            'v1.auth.password.reset' => ['Set a new password', 'Uses a valid one-time recovery code to set a password and revoke active sessions.'],
            'v1.auth.password.change' => ['Change the current password', 'Changes the signed-in identity password after checking the current password and revokes other sessions.'],
            'v1.auth.verification.request' => ['Request contact verification', 'Requests a one-time code for an email or international phone contact. The response is generic to prevent contact enumeration.'],
            'v1.auth.verification.confirm' => ['Confirm a contact', 'Confirms a one-time email or phone code and marks only the matching contact as verified.'],
            'v1.auth.context.switch' => ['Switch the active school', 'Selects an active school membership for this authentication session. The school is stored server-side and is not added to the JWT.'],
            'v1.auth.context' => ['View the active school', 'Returns the school currently selected for this authentication session, if one is selected.'],
            'v1.me.memberships' => ['List my school memberships', 'Lists the signed-in identity’s school memberships without requiring an active school selection.'],
            'v1.public.school-registrations' => ['Submit a school registration', 'Creates a provisional intake record only. It does not create a school, identity, membership, password, session or tenant context.'],
            'v1.public.school-registrations.verification.request' => ['Request registration verification', 'Requests a verification code for the contact stored on a provisional registration. The response does not reveal registration state.'],
            'v1.public.school-registrations.verification.confirm' => ['Confirm registration verification', 'Confirms a six-digit code and changes only the registration status to verified.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.show' => ['View an assessment scheme', 'Shows the school-defined assessment components and maximum marks for a subject offering and term. This endpoint does not return student scores or calculate grades.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.update' => ['Replace an assessment scheme', 'Replaces the complete assessment component configuration for a subject offering and term. Component maximum marks must add up to the configured total, and the entire update is rejected if validation fails.'],
        ];

        if (isset($fixed[$operationId])) {
            return $fixed[$operationId];
        }

        $resource = $this->resourceName($operationId);
        $action = match (true) {
            str_ends_with((string) $operationId, '.index') => 'List',
            str_ends_with((string) $operationId, '.store') => 'Create',
            str_ends_with((string) $operationId, '.show') => 'View',
            str_ends_with((string) $operationId, '.update') => 'Update',
            str_ends_with((string) $operationId, '.activate') => 'Activate',
            str_ends_with((string) $operationId, '.deactivate') => 'Deactivate',
            str_ends_with((string) $operationId, '.close') => 'Close',
            str_ends_with((string) $operationId, '.revoke') => 'Revoke',
            default => 'Manage',
        };

        return [
            $action.' '.$resource,
            $action.'s '.$resource.' within the trusted selected school. Route identifiers select records only; authorization comes from the signed-in identity’s active membership and permissions.',
        ];
    }

    private function resourceName(?string $operationId): string
    {
        $operationId = (string) $operationId;

        return match (true) {
            str_contains($operationId, 'teaching-assignments') => 'teaching assignments',
            str_contains($operationId, 'assessment-scheme') => 'assessment scheme',
            str_contains($operationId, 'subject-offerings') => 'subject offerings',
            str_contains($operationId, 'class-arms') => 'class arms',
            str_contains($operationId, 'academic-levels.sections') => 'academic sections',
            str_contains($operationId, 'academic-levels') => 'academic levels',
            str_contains($operationId, 'academic-sessions.terms') => 'academic terms',
            str_contains($operationId, 'academic-sessions') => 'academic sessions',
            str_contains($operationId, 'subjects') => 'subjects',
            str_contains($operationId, 'invitations') => 'school invitations',
            str_contains($operationId, 'roles.catalogue') => 'school roles',
            str_contains($operationId, 'memberships.roles') => 'membership roles',
            str_contains($operationId, 'setup') => 'school setup progress',
            str_contains($operationId, 'profile.logo') => 'school logo',
            str_contains($operationId, 'profile') => 'school profile',
            str_contains($operationId, 'lifecycle') => 'school lifecycle',
            str_contains($operationId, 'permissions') => 'school permissions',
            default => 'the requested resource',
        };
    }

    private function parameterDescription(string $name, string $location): string
    {
        return match ($name) {
            'school' => 'Public identifier of the school selected by the authenticated session.',
            'session' => 'Public identifier of the academic session.',
            'term' => 'Public identifier of the academic term.',
            'level' => 'Public identifier of the academic level.',
            'section' => 'Public identifier of the academic section.',
            'classArm' => 'Public identifier of the class arm.',
            'subject' => 'Public identifier of the school subject.',
            'offering' => 'Public identifier of the subject offering.',
            'assignment' => 'Public identifier of the teaching assignment.',
            'membership' => 'Public identifier of the school membership.',
            'role' => 'Public identifier of the assigned school role.',
            'invitation' => 'Public identifier of the school invitation.',
            'registration' => 'Public identifier of the provisional registration.',
            'item' => 'Approved setup item key, such as school_details.',
            'school_id' => 'Compatibility selector for the school file. It must match the trusted school context.',
            'public_id' => 'Server-generated public file identifier. It is not an authorization credential.',
            'Idempotency-Key' => 'Client-generated retry key. Reuse it only with the same registration request payload.',
            default => $location === 'header' ? 'Request header used for API correlation or transport control.' : 'Public request value used to select the requested resource.',
        };
    }

    private function requestBodyDescription(?string $operationId): string
    {
        return match (true) {
            $operationId === 'v1.auth.login' => 'Send a verified email address or international phone number and the password for the identity.',
            str_contains((string) $operationId, 'school-registrations') && str_ends_with((string) $operationId, 'verification.confirm') => 'Send the six-digit code delivered for this provisional registration.',
            str_contains((string) $operationId, 'teaching-assignments') => 'Send the teacher public ID, term-contained effective dates and an optional administrative reason.',
            str_contains((string) $operationId, 'assessment-scheme') => 'Send the school-defined scheme name, total marks and at least one component. Component names and sequence values must be unique and their maximum marks must equal the total.',
            str_contains((string) $operationId, 'academic-sessions') => 'Send the academic period name, optional code and inclusive date range. Lifecycle state is managed by the server.',
            str_contains((string) $operationId, 'subject-offerings') => 'Send the subject and class-arm public IDs. The selected school, term and lifecycle state are server-controlled.',
            str_contains((string) $operationId, 'school-registrations') => 'Send fictional school details, a normalized email or international phone contact, and the consent version.',
            default => 'Send the fields required for this operation. Server-managed identifiers, ownership, actor, lifecycle and timestamp fields are not accepted as authority.',
        };
    }

    private function addRequestSchemaDocumentation(Components $components): void
    {
        foreach ($components->schemas as $schemaName => $schema) {
            if (! str_ends_with($schemaName, 'Request') || ! $schema->type instanceof ObjectType) {
                continue;
            }

            foreach ($schema->type->properties as $propertyName => $property) {
                if ($property === null) {
                    continue;
                }

                [$description, $example] = $this->requestPropertyDocumentation($schemaName, $propertyName);
                $property->setDescription($description);

                if ($example !== null) {
                    $property->example($example);
                }
            }
        }
    }

    /** @return array{0: string, 1: scalar|null} */
    private function requestPropertyDocumentation(string $schemaName, string $propertyName): array
    {
        return match ($propertyName) {
            'login' => ['Verified email address or international phone number used to sign in or recover access.', 'owner@example.com'],
            'password' => ['Password value. Send it over HTTPS and never log or store it in client telemetry.', null],
            'password_confirmation' => ['Repeat the new password so the server can confirm it was entered correctly.', null],
            'current_password' => ['The current password required before changing it.', null],
            'client' => ['Client transport type. Use api for bearer-token clients or browser for cookie-based clients.', 'api'],
            'refresh_token' => ['Opaque refresh token returned by a previous sign-in or refresh operation.', null],
            'csrf_token' => ['CSRF value required for browser cookie flows when the endpoint requests one.', null],
            'code' => [
                str_contains($schemaName, 'Verification') || str_contains($schemaName, 'Reset')
                    ? 'Six-digit one-time code delivered for the selected verification or recovery flow.'
                    : 'Optional stable code unique within the relevant school scope.',
                str_contains($schemaName, 'Verification') || str_contains($schemaName, 'Reset') ? null : 'Y2025',
            ],
            'contact' => ['Email address or international phone number to normalize for this request.', 'owner@example.com'],
            'school_name' => ['Display name for the provisional school record.', 'Fictional Academy'],
            'school_type' => ['School type as a bounded school-defined value; no global catalogue is assumed.', 'secondary'],
            'state' => ['Nigerian state or region written as a bounded display value.', 'Lagos'],
            'consent_version' => ['Version of the registration consent text accepted by the requester.', 'v1'],
            'name' => ['Human-readable name for this school-owned academic record.', 'Year 2025/2026'],
            'classification' => ['Optional school-defined subject classification.', 'core'],
            'stage' => ['Optional education-stage label chosen by the school.', 'secondary'],
            'sequence' => ['Display order or term sequence within the parent academic structure.', 1],
            'start_date' => ['Inclusive start date in YYYY-MM-DD format.', '2025-09-01'],
            'end_date' => ['Inclusive end date in YYYY-MM-DD format.', '2026-07-31'],
            'capacity' => ['Positive maximum number of learners for this class arm; occupancy is managed later.', 30],
            'display_order' => ['Optional display order for the subject offering.', 1],
            'class_arm_id' => ['Public identifier of the class arm receiving the subject offering.', '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1005'],
            'subject_id' => ['Public identifier of the reusable subject catalogue entry.', '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1006'],
            'teacher_id' => ['Public identifier of the eligible teacher identity.', '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1007'],
            'effective_start' => ['Assignment start date, which must fall within the academic term.', '2025-09-01'],
            'effective_end' => ['Optional assignment end date within the academic term.', '2026-07-31'],
            'reason' => ['Short administrative reason for the requested change. Do not include secrets or sensitive personal data.', 'Fictional administrative update'],
            'confirmation' => ['Required archive guard value. This confirms intent but does not grant permission.', 'ARCHIVE'],
            'roles' => ['Predefined school role public identifiers to assign; arbitrary permissions are not accepted.', null],
            'token' => ['Opaque invitation token supplied to accept the invitation.', null],
            'address_line1' => ['Primary school address line.', '12 Example Street'],
            'address_line2' => ['Optional secondary address line.', 'Ikeja'],
            'city' => ['School city or town.', 'Lagos'],
            'postal_code' => ['Optional postal code.', '100001'],
            'timezone' => ['IANA timezone for school-local dates and times.', 'Africa/Lagos'],
            'status' => ['Requested setup state; the server accepts only the approved state transitions.', 'completed'],
            'school_id' => ['Public school selector. The authenticated session context remains the authority.', '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1001'],
            default => ['Input value for this operation. Server-managed ownership, actor, lifecycle and timestamp fields are not trusted.', null],
        };
    }

    private function tagForOperation(?string $operationId): string
    {
        if (in_array($operationId, ['v1.health', 'v1.health.readiness', 'v1.files.download'], true)) {
            return 'Platform';
        }

        if (in_array($operationId, [
            'v1.auth.login',
            'v1.auth.refresh',
            'v1.auth.me',
            'v1.auth.logout',
            'v1.auth.logout_all',
            'v1.auth.password.forgot',
            'v1.auth.password.reset',
            'v1.auth.password.change',
            'v1.auth.verification.request',
            'v1.auth.verification.confirm',
        ], true)) {
            return 'Identity & Authentication';
        }

        if (in_array($operationId, [
            'v1.auth.context.switch',
            'v1.auth.context',
            'v1.me.memberships',
            'v1.schools.invitations.create',
            'v1.invitations.accept',
            'v1.invitations.revoke',
            'v1.schools.roles.catalogue',
            'v1.schools.memberships.roles',
            'v1.schools.memberships.roles.assign',
            'v1.schools.memberships.roles.revoke',
            'v1.me.schools.permissions',
            'v1.schools.setup.index',
            'v1.schools.setup.update',
            'v1.schools.profile.show',
            'v1.schools.profile.update',
            'v1.schools.profile.logo.upload',
            'v1.schools.profile.logo.remove',
            'v1.schools.lifecycle.show',
            'v1.schools.lifecycle.suspend',
            'v1.schools.lifecycle.reactivate',
            'v1.schools.lifecycle.archive',
            'v1.schools.academic-sessions.index',
            'v1.schools.academic-sessions.store',
            'v1.schools.academic-sessions.show',
            'v1.schools.academic-sessions.update',
            'v1.schools.academic-sessions.activate',
            'v1.schools.academic-sessions.close',
            'v1.schools.academic-sessions.terms.index',
            'v1.schools.academic-sessions.terms.store',
            'v1.schools.academic-sessions.terms.update',
            'v1.schools.academic-sessions.terms.activate',
            'v1.schools.academic-sessions.terms.close',
            'v1.schools.academic-sessions.terms.subject-offerings.index',
            'v1.schools.academic-sessions.terms.subject-offerings.store',
            'v1.schools.academic-sessions.terms.subject-offerings.show',
            'v1.schools.academic-sessions.terms.subject-offerings.update',
            'v1.schools.academic-sessions.terms.subject-offerings.activate',
            'v1.schools.academic-sessions.terms.subject-offerings.deactivate',
            'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.index',
            'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.store',
            'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.show',
            'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.update',
            'v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.revoke',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.show',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.update',
            'v1.schools.academic-context',
            'v1.schools.academic-levels.index',
            'v1.schools.academic-levels.store',
            'v1.schools.academic-levels.show',
            'v1.schools.academic-levels.update',
            'v1.schools.academic-levels.activate',
            'v1.schools.academic-levels.deactivate',
            'v1.schools.academic-levels.sections.index',
            'v1.schools.academic-levels.sections.store',
            'v1.schools.academic-levels.sections.show',
            'v1.schools.academic-levels.sections.update',
            'v1.schools.academic-levels.sections.activate',
            'v1.schools.academic-levels.sections.deactivate',
            'v1.schools.academic-levels.sections.class-arms.index',
            'v1.schools.academic-levels.sections.class-arms.store',
            'v1.schools.academic-levels.sections.class-arms.show',
            'v1.schools.academic-levels.sections.class-arms.update',
            'v1.schools.academic-levels.sections.class-arms.activate',
            'v1.schools.academic-levels.sections.class-arms.deactivate',
            'v1.schools.subjects.index',
            'v1.schools.subjects.store',
            'v1.schools.subjects.show',
            'v1.schools.subjects.update',
            'v1.schools.subjects.activate',
            'v1.schools.subjects.deactivate',
        ], true)) {
            return 'School Access';
        }

        if (in_array($operationId, [
            'v1.public.school-registrations',
            'v1.public.school-registrations.verification.request',
            'v1.public.school-registrations.verification.confirm',
        ], true)) {
            return 'School Registration';
        }

        return 'Platform';
    }

    private function errorType(): Schema
    {
        $error = (new ObjectType)
            ->addProperty('code', new StringType)
            ->addProperty('message', new StringType)
            ->addProperty('details', new ObjectType)
            ->addProperty('request_id', (new StringType)->format('uuid'))
            ->setRequired(['code', 'message', 'details', 'request_id']);

        return Schema::fromType($error);
    }

    private function addRequestIdHeaders(Operation $operation): void
    {
        foreach ($operation->responses as $response) {
            if (! $response instanceof Response) {
                continue;
            }

            $response->addHeader('X-Request-ID', new Header(
                description: 'Request correlation identifier.',
                required: true,
                schema: Schema::fromType((new StringType)->format('uuid')),
            ));
        }
    }

    private function addStandardErrorResponses(Operation $operation, Components $components): void
    {
        foreach ([
            401 => 'Authentication is required or the supplied credentials are invalid.',
            403 => 'The authenticated actor is not authorized for this operation.',
            404 => 'The requested resource was not found.',
            405 => 'The HTTP method is not supported for this route.',
            422 => 'The request failed validation.',
            500 => 'The server could not complete the request.',
            503 => 'The service is temporarily unavailable.',
        ] as $status => $description) {
            $operation->addResponse($this->errorResponse($status, $description, $components));
        }
    }

    private function errorResponse(int $status, string $description, Components $components): Response
    {
        $envelope = (new ObjectType)
            ->addProperty('error', new Reference('schemas', 'ApiError', $components))
            ->setRequired(['error']);

        return Response::make($status)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($envelope))
            ->addHeader('X-Request-ID', new Header(
                description: 'Request correlation identifier.',
                required: true,
                schema: Schema::fromType((new StringType)->format('uuid')),
            ));
    }
}
