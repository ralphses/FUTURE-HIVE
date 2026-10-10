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
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
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
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.index',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.show',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.store',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.activate',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.retire',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.index',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.show',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.store',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.activate',
                    'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.retire',
                    'v1.schools.promotion-rules.index',
                    'v1.schools.promotion-rules.store',
                    'v1.schools.promotion-rules.show',
                    'v1.schools.promotion-rules.update',
                    'v1.schools.promotion-rules.activate',
                    'v1.schools.promotion-rules.deactivate',
                    'v1.schools.academic-context',
                    'v1.schools.academic-readiness',
                    'v1.schools.students.index',
                    'v1.schools.students.store',
                    'v1.schools.students.show',
                    'v1.schools.students.update',
                    'v1.schools.students.activate',
                    'v1.schools.students.withdraw',
                    'v1.schools.students.enrollments.index',
                    'v1.schools.students.enrollments.store',
                    'v1.schools.students.enrollments.show',
                    'v1.schools.students.enrollments.end',
                    'v1.schools.students.profile.show',
                    'v1.schools.students.profile.update',
                    'v1.schools.students.documents.index',
                    'v1.schools.students.documents.store',
                    'v1.students.documents.download',
                    'v1.schools.students.documents.revoke',
                    'v1.schools.guardians.index',
                    'v1.schools.guardians.show',
                    'v1.schools.students.guardian-relationships.index',
                    'v1.schools.students.guardian-relationships.store',
                    'v1.schools.students.guardian-relationships.update',
                    'v1.schools.students.guardian-relationships.revoke',
                    'v1.schools.students.guardian-relationships.invitation.request',
                    'v1.guardian-invitations.confirm',
                    'v1.schools.guardian-links.index',
                    'v1.schools.guardian-invitations.revoke',
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
            new Tag('School Registration', 'Unauthenticated provisional registration and registration-bound verification.'),
            new Tag('School Context & Memberships', 'School selection, memberships, invitations, roles and permissions for authenticated users.'),
            new Tag('School Administration', 'School setup, profile, branding and lifecycle settings managed within a trusted school context.'),
            new Tag('Academic Structure', 'School-defined academic periods, structure, subjects, offerings and teaching assignments.'),
            new Tag('Academic Assessment', 'School-defined assessment schemes, policy versions, grading scales, promotion rules and readiness checks.'),
            new Tag('Student Registry', 'School-owned student admissions, profiles and private student documents.'),
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
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.index' => ['List assessment policy versions', 'Lists immutable assessment policy snapshots for a subject offering and term, including draft, active and retired versions.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.show' => ['View an assessment policy version', 'Shows one immutable assessment policy snapshot and its component marks. Historical versions remain available for later score and result references.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.store' => ['Create an assessment policy version', 'Creates a draft snapshot from the current assessment scheme. The offering must be active and the dates must fall within the open term.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.activate' => ['Activate an assessment policy version', 'Activates one draft policy version when no competing active version exists for the offering and term.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.retire' => ['Retire an assessment policy version', 'Retires an active policy version without deleting its historical component snapshot.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.index' => ['List grading scales', 'Lists grading scale versions attached to an assessment policy, including draft, active and retired configurations.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.show' => ['View a grading scale', 'Shows one school-defined grading scale with inclusive percentage bands, pass status and optional remarks.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.store' => ['Create a grading scale', 'Creates a draft grading scale for an assessment policy. Bands must cover every percentage from 0 through 100 without gaps or overlaps.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.activate' => ['Activate a grading scale', 'Activates one draft grading scale when its assessment policy is active and no other scale is active for that policy.'],
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.retire' => ['Retire a grading scale', 'Retires an active grading scale while preserving its historical bands for future result references.'],
            'v1.schools.promotion-rules.index' => ['List promotion rules', 'Lists the school’s level-to-level promotion rule configurations. This is configuration only and does not evaluate students or make promotion decisions.'],
            'v1.schools.promotion-rules.store' => ['Create a promotion rule', 'Creates a draft rule for moving learners from one school-defined academic level to another using reusable criteria.'],
            'v1.schools.promotion-rules.show' => ['View a promotion rule', 'Shows one school-defined promotion rule and its criteria.'],
            'v1.schools.promotion-rules.update' => ['Replace a promotion rule', 'Replaces a draft or inactive promotion rule atomically. Criteria use approved metrics and the greater-than-or-equal operator.'],
            'v1.schools.promotion-rules.activate' => ['Activate a promotion rule', 'Activates a rule when no other active rule exists for the same source and target levels.'],
            'v1.schools.promotion-rules.deactivate' => ['Deactivate a promotion rule', 'Deactivates an active rule while retaining its configuration for later review.'],
            'v1.schools.academic-readiness' => ['Check academic readiness', 'Checks whether the selected school has the academic configuration needed for future assessment capture and report publication. This read-only response identifies missing or invalid configuration without exposing internal records or student data.'],
            'v1.schools.students.index' => ['List students', 'Lists student admission records in the selected school. This endpoint does not return credentials, guardian relationships or enrolment data.'],
            'v1.schools.students.store' => ['Admit a student', 'Creates a pending student admission record in the selected school. It does not create an identity, login, guardian relationship or enrolment.'],
            'v1.schools.students.show' => ['View a student admission', 'Returns one student admission record after trusted school authorization.'],
            'v1.schools.students.update' => ['Update student admission details', 'Updates the school-owned admission number and display details without changing lifecycle state.'],
            'v1.schools.students.activate' => ['Activate a student admission', 'Moves a pending student admission to active. Withdrawn and archived records cannot be reactivated.'],
            'v1.schools.students.withdraw' => ['Withdraw a student', 'Marks an active student admission as withdrawn while retaining the historical record.'],
            'v1.schools.students.enrollments.index' => ['List student enrollments', 'Lists the selected student’s term enrollments in the trusted school. Internal identifiers and unrelated school records are never returned.'],
            'v1.schools.students.enrollments.store' => ['Enroll a student', 'Places an active student into an active class arm for an academic term. Parent records and ownership are resolved by the server; the request cannot create identity or guardian access.'],
            'v1.schools.students.enrollments.show' => ['View a student enrollment', 'Returns one school-scoped enrollment using its public identifier.'],
            'v1.schools.students.enrollments.end' => ['End a student enrollment', 'Administratively ends an active enrollment and retains its history. Transfers and replacement placement are handled by a later workflow.'],
            'v1.schools.students.profile.show' => ['View a student profile', 'Returns approved profile fields for one student in the selected school. It does not include guardian, enrolment or attendance data.'],
            'v1.schools.students.profile.update' => ['Update a student profile', 'Creates or replaces the bounded profile for an admitted student without changing admission status or school ownership.'],
            'v1.schools.students.documents.index' => ['List student documents', 'Lists active private documents attached to a student. Download links are short-lived and school-authorized.'],
            'v1.schools.students.documents.store' => ['Upload a student document', 'Scans and stores a controlled student document as a private authenticated Cloudinary asset.'],
            'v1.students.documents.download' => ['Get a student document link', 'Returns a short-lived application-signed download link. The provider URL is never exposed.'],
            'v1.schools.students.documents.revoke' => ['Revoke a student document', 'Revokes a private student document while retaining its audit and historical metadata.'],
            'v1.schools.guardians.index' => ['List school guardians', 'Lists existing guardian profiles linked to students in the selected school. It does not create identities or activate guardian access.'],
            'v1.schools.guardians.show' => ['View a guardian profile', 'Returns bounded display information for a guardian linked to the selected school without exposing contacts or credentials.'],
            'v1.schools.students.guardian-relationships.index' => ['List student guardian relationships', 'Lists pending or active guardian relationships for a student. Pending relationships do not grant student-data access.'],
            'v1.schools.students.guardian-relationships.store' => ['Add a guardian relationship', 'Links an existing IAM identity to a student as a pending relationship. It does not send an invitation or verify access.'],
            'v1.schools.students.guardian-relationships.update' => ['Update a guardian relationship', 'Updates the relationship type or safe display metadata while keeping verification and lifecycle state server-controlled.'],
            'v1.schools.students.guardian-relationships.revoke' => ['Revoke a guardian relationship', 'Revokes a relationship while retaining its history. It does not delete the guardian profile or identity.'],
            'v1.schools.students.guardian-relationships.invitation.request' => ['Request guardian access', 'Creates a short-lived invitation for a pending relationship. The relationship remains pending until the invited identity confirms the one-time code.'],
            'v1.guardian-invitations.confirm' => ['Confirm guardian access', 'Confirms an invitation code for the signed-in invited guardian and activates only the bound relationship. No school membership or student JWT claims are created.'],
            'v1.schools.guardian-links.index' => ['List my active student links', 'Lists active, verified student relationships for the signed-in guardian in the selected school. Pending and revoked relationships are excluded.'],
            'v1.schools.guardian-invitations.revoke' => ['Revoke a guardian invitation', 'Revokes an unused invitation while retaining its history. It does not delete the guardian identity or relationship.'],
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
            str_contains((string) $operationId, 'grading-scales') && str_ends_with((string) $operationId, '.store') => 'Send the scale name, effective dates and contiguous grading bands covering the inclusive 0–100 percentage range.',
            str_contains((string) $operationId, 'promotion-rules') && str_ends_with((string) $operationId, '.store') => 'Send the source and target academic levels, rule description and approved promotion criteria. The server controls school ownership and lifecycle.',
            str_contains((string) $operationId, 'students.profile') => 'Send the student profile names, optional date of birth, bounded gender value and internal notes. Admission status and school ownership remain server-controlled.',
            str_contains((string) $operationId, 'students.documents.store') => 'Upload one approved student document category and file. The file is malware-scanned before private authenticated Cloudinary storage; the server controls the student and school references.',
            str_contains((string) $operationId, 'students.documents.revoke') => 'Send the bounded administrative reason for revoking the document. Revocation retains the document record and does not expose the provider URL.',
            str_contains((string) $operationId, 'guardian-relationships') && str_ends_with((string) $operationId, '.store') => 'Send the existing guardian identity public ID, approved relationship type and optional safe display metadata. The server creates only a pending school-scoped relationship.',
            str_contains((string) $operationId, 'guardian-relationships') && str_ends_with((string) $operationId, '.update') => 'Send the approved relationship type and optional safe display metadata. Verification, school ownership and lifecycle status remain server-controlled.',
            str_contains((string) $operationId, 'guardian-relationships') && str_ends_with((string) $operationId, '.revoke') => 'Send a bounded administrative reason. The relationship is retained as revoked and no invitation or identity is deleted.',
            str_contains((string) $operationId, 'guardian-relationships.invitation.request') => 'No request body is required. The server uses the pending relationship and trusted school context to create the invitation.',
            $operationId === 'v1.guardian-invitations.confirm' => 'Send the six-digit code delivered to the invited guardian. The signed-in identity must match the bound guardian profile.',
            $operationId === 'v1.schools.guardian-invitations.revoke' => 'Send a bounded administrative reason. The invitation is retained as revoked and its code is never returned or stored in plaintext.',
            str_contains((string) $operationId, 'lifecycle.') => 'Send a bounded administrative reason for the requested school lifecycle transition. The server validates the current state and allowed transition.',
            str_contains((string) $operationId, 'setup.update') => 'Send the approved setup item status. The selected school, item ownership and completion timestamp are controlled by the server.',
            str_contains((string) $operationId, 'profile.update') => 'Send the school contact, bounded address fields and IANA timezone. The selected school and audit actor come from trusted server context.',
            str_contains((string) $operationId, 'roles.assign') => 'Send predefined school role public identifiers to assign to the selected membership. The server resolves the membership and school context.',
            str_contains((string) $operationId, 'invitations') => 'Send the invitee contact or bounded invitation action fields. The server resolves the school, inviter and invitation state from trusted context.',
            str_contains((string) $operationId, 'auth.password') => 'Send the credentials or one-time recovery values required for this password operation. Passwords and codes are never returned or logged.',
            str_contains((string) $operationId, 'auth.verification') => 'Send the canonical contact and six-digit code required for this contact-verification operation.',
            str_contains((string) $operationId, 'auth.context.switch') => 'Send the public school selector to choose an active membership for this session. The server validates ownership and membership status.',
            str_contains((string) $operationId, 'auth.logout') => 'No request body is required. The authenticated session is identified from the bearer token or protected browser flow.',
            str_contains((string) $operationId, 'auth.refresh') => 'Send the opaque refresh token and client transport type. The server rotates the token and invalidates the previous value.',
            str_contains((string) $operationId, 'academic-sessions') => 'Send the academic period name, optional code and inclusive date range. Lifecycle state is managed by the server.',
            str_contains((string) $operationId, 'subject-offerings') => 'Send the subject and class-arm public IDs. The selected school, term and lifecycle state are server-controlled.',
            str_contains((string) $operationId, 'school-registrations') => 'Send fictional school details, a normalized email or international phone contact, and the consent version.',
            default => 'Send the documented request fields for this operation. Public selectors identify the requested resource, while school ownership, actor identity, lifecycle state and timestamps are controlled by the server.',
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
                $property->setExtensionProperty('field-label', $this->fieldLabel($schemaName, $propertyName));

                if ($example !== null) {
                    $property->example($example);
                }

                $this->documentNestedRequestProperties($schemaName, $property);
            }
        }
    }

    private function documentNestedRequestProperties(string $schemaName, object $property): void
    {
        if ($property instanceof ObjectType) {
            foreach ($property->properties as $nestedName => $nestedProperty) {
                if ($nestedProperty === null) {
                    continue;
                }

                [$description, $example] = $this->requestPropertyDocumentation($schemaName, $nestedName);
                $nestedProperty->setDescription($description);
                $nestedProperty->setExtensionProperty('field-label', $this->fieldLabel($schemaName, $nestedName));

                if ($example !== null) {
                    $nestedProperty->example($example);
                }

                $this->documentNestedRequestProperties($schemaName, $nestedProperty);
            }
        }

        if ($property instanceof ArrayType) {
            $this->documentNestedRequestProperties($schemaName, $property->items);
        }
    }

    private function fieldLabel(string $schemaName, string $propertyName): string
    {
        return match ($propertyName) {
            'student_number' => 'Student number',
            'display_name' => 'Display name',
            'admission_date' => 'Admission date',
            'metadata' => 'Admission metadata',
            'legal_name' => 'Legal name',
            'preferred_name' => 'Preferred name',
            'date_of_birth' => 'Date of birth',
            'gender' => 'Gender',
            'notes' => 'Internal notes',
            'total_marks' => 'Total marks',
            'components' => 'Assessment components',
            'bands' => 'Grading bands',
            'criteria' => 'Promotion criteria',
            'source_level_id' => 'Source academic level',
            'target_level_id' => 'Target academic level',
            'max_marks' => 'Maximum marks',
            'grade' => 'Grade key',
            'minimum_percentage' => 'Minimum percentage',
            'maximum_percentage' => 'Maximum percentage',
            'is_passing' => 'Passing grade',
            'min_percentage' => 'Minimum percentage',
            'max_percentage' => 'Maximum percentage',
            'grade_key' => 'Grade key',
            'label' => 'Display label',
            'passing' => 'Passing grade',
            'remark' => 'Optional remark',
            'metric' => 'Promotion metric',
            'operator' => 'Comparison operator',
            'threshold' => 'Required threshold',
            'required' => 'Required criterion',
            'document' => 'Document file',
            default => str($propertyName)->replace('_', ' ')->title()->toString(),
        };
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
            'student_number' => ['School-local admission or student number. It must be unique within the selected school.', 'STU-2025-001'],
            'display_name' => ['Name shown in school records and lists.', 'Amina Example'],
            'admission_date' => ['Date the student was admitted, in YYYY-MM-DD format.', '2025-09-01'],
            'metadata' => ['Optional non-sensitive admission metadata defined by the school.', null],
            'legal_name' => ['Student’s legal name as recorded by the school.', 'Amina Example'],
            'preferred_name' => ['Optional name the student prefers to use in school communications.', 'Mina'],
            'date_of_birth' => ['Optional date of birth, in YYYY-MM-DD format.', '2014-04-12'],
            'gender' => ['Optional bounded gender value used by the school record.', 'undisclosed'],
            'notes' => ['Optional bounded internal profile note. Do not include credentials or unnecessary sensitive data.', null],
            'total_marks' => ['Total marks available for the school-defined assessment scheme.', 100],
            'components' => ['Assessment components whose maximum marks must add up to total_marks.', null],
            'bands' => ['Grading bands that must cover the complete inclusive 0–100 percentage range.', null],
            'max_marks' => ['Maximum marks assigned to this assessment component.', 20],
            'criteria' => ['Ordered criteria used by a later promotion-evaluation workflow.', null],
            'grade' => ['Stable school-defined key for the grading band.', 'D'],
            'minimum_percentage' => ['Inclusive lower percentage boundary for this grading band.', 0],
            'maximum_percentage' => ['Inclusive upper percentage boundary for this grading band.', 49.99],
            'is_passing' => ['Whether this grading band counts as passing.', false],
            'label' => ['Human-readable label for the grading band.', 'Needs improvement'],
            'passing' => ['Whether this band counts as passing.', false],
            'remark' => ['Optional bounded remark displayed with this grade band.', 'Keep practising'],
            'source_level_id' => ['Public identifier of the academic level students move from.', '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1008'],
            'target_level_id' => ['Public identifier of the academic level students move to.', '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1009'],
            'metric' => ['Approved promotion metric evaluated later by the promotion workflow.', 'overall_percentage'],
            'operator' => ['Comparison used for the criterion threshold.', 'gte'],
            'threshold' => ['Numeric value required for the selected promotion metric.', 50],
            'required' => ['Whether this criterion is required for a future promotion decision.', true],
            'description' => ['Optional plain-language explanation of the school-defined rule.', 'Learners must meet the listed academic criteria.'],
            'category' => [
                str_contains($schemaName, 'Assessment') ? 'Assessment component category: continuous assessment, test or examination.' : 'Student-document category accepted by the private document store.',
                str_contains($schemaName, 'Assessment') ? 'exam' : 'birth_certificate',
            ],
            'document' => ['File to upload. It is scanned before private authenticated storage and is limited to approved formats and size.', null],
            'guardian_id' => ['Public identifier of an existing IAM identity to link as a pending guardian. It is not an internal user ID.', '0192f2a0-7c2b-7b1a-8d31-4f6b9c2a1010'],
            'relationship_type' => ['School-defined relationship between the guardian and student.', 'parent'],
            'code' => ['Six-digit one-time code delivered for the guardian invitation.', null],
            'reason' => ['Short administrative reason for revoking the invitation.', 'Fictional administrative change'],
            default => ['Request field for this operation. The server controls ownership, actor, lifecycle and timestamps.', null],
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
        ], true)) {
            return 'School Context & Memberships';
        }

        if (in_array($operationId, [
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
            return 'School Administration';
        }

        if (in_array($operationId, [
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.show',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.update',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.index',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.show',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.store',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.activate',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.retire',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.index',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.show',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.store',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.activate',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.retire',
            'v1.schools.promotion-rules.index',
            'v1.schools.promotion-rules.store',
            'v1.schools.promotion-rules.show',
            'v1.schools.promotion-rules.update',
            'v1.schools.promotion-rules.activate',
            'v1.schools.promotion-rules.deactivate',
            'v1.schools.academic-readiness',
        ], true)) {
            return 'Academic Assessment';
        }

        if (in_array($operationId, [
            'v1.schools.students.index',
            'v1.schools.students.store',
            'v1.schools.students.show',
            'v1.schools.students.update',
            'v1.schools.students.activate',
            'v1.schools.students.withdraw',
            'v1.schools.students.enrollments.index',
            'v1.schools.students.enrollments.store',
            'v1.schools.students.enrollments.show',
            'v1.schools.students.enrollments.end',
            'v1.schools.students.profile.show',
            'v1.schools.students.profile.update',
            'v1.schools.students.documents.index',
            'v1.schools.students.documents.store',
            'v1.students.documents.download',
            'v1.schools.students.documents.revoke',
            'v1.schools.guardians.index',
            'v1.schools.guardians.show',
            'v1.schools.students.guardian-relationships.index',
            'v1.schools.students.guardian-relationships.store',
            'v1.schools.students.guardian-relationships.update',
            'v1.schools.students.guardian-relationships.revoke',
            'v1.schools.students.guardian-relationships.invitation.request',
            'v1.guardian-invitations.confirm',
            'v1.schools.guardian-links.index',
            'v1.schools.guardian-invitations.revoke',
        ], true)) {
            return 'Student Registry';
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
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.index',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.show',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.store',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.activate',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.retire',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.index',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.show',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.store',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.activate',
            'v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.retire',
            'v1.schools.promotion-rules.index',
            'v1.schools.promotion-rules.store',
            'v1.schools.promotion-rules.show',
            'v1.schools.promotion-rules.update',
            'v1.schools.promotion-rules.activate',
            'v1.schools.promotion-rules.deactivate',
            'v1.schools.academic-context',
            'v1.schools.academic-readiness',
            'v1.schools.students.index',
            'v1.schools.students.store',
            'v1.schools.students.show',
            'v1.schools.students.update',
            'v1.schools.students.activate',
            'v1.schools.students.withdraw',
            'v1.schools.students.profile.show',
            'v1.schools.students.profile.update',
            'v1.schools.students.documents.index',
            'v1.schools.students.documents.store',
            'v1.students.documents.download',
            'v1.schools.students.documents.revoke',
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
            return 'Academic Structure';
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
