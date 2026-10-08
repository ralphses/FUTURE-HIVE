<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
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

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $operation->setTags([$this->tagForOperation($operation->operationId)]);
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
