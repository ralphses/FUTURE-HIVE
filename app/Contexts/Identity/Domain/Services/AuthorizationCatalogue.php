<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Services;

final class AuthorizationCatalogue
{
    /** @return array<string, array{label: string, description: string}> */
    public static function roles(): array
    {
        return [
            'school_admin' => ['label' => 'School Admin', 'description' => 'Manages approved school configuration and membership operations.'],
            'teacher' => ['label' => 'Teacher', 'description' => 'Works within assigned teaching scope.'],
            'hod_reviewer' => ['label' => 'HOD / Reviewer', 'description' => 'Reviews assigned academic work.'],
            'principal' => ['label' => 'Principal', 'description' => 'Approves school academic operations within policy.'],
            'bursar' => ['label' => 'Bursar', 'description' => 'Manages permitted school finance operations.'],
            'proprietor' => ['label' => 'Proprietor', 'description' => 'Views permitted school-level aggregates and approvals.'],
            'counsellor' => ['label' => 'Counsellor', 'description' => 'Works with referred learners and counselling plans.'],
            'parent_guardian' => ['label' => 'Parent / Guardian', 'description' => 'Accesses verified linked learner information.'],
            'student' => ['label' => 'Student', 'description' => 'Accesses the student’s eligible published information.'],
        ];
    }

    /** @return array<string, array{label: string, description: string}> */
    public static function permissions(): array
    {
        return [
            'school.memberships.list' => ['label' => 'List memberships', 'description' => 'View active memberships for a school.'],
            'school.memberships.invite' => ['label' => 'Invite members', 'description' => 'Invite verified contacts to a school.'],
            'school.memberships.revoke' => ['label' => 'Revoke memberships', 'description' => 'Revoke a school membership.'],
            'school.roles.assign' => ['label' => 'Assign roles', 'description' => 'Assign predefined roles to school memberships.'],
            'school.roles.revoke' => ['label' => 'Revoke roles', 'description' => 'Revoke assigned school roles.'],
            'school.settings.read' => ['label' => 'Read school settings', 'description' => 'View school configuration.'],
            'school.settings.manage' => ['label' => 'Manage school settings', 'description' => 'Change approved school configuration.'],
            'school.lifecycle.read' => ['label' => 'Read school lifecycle', 'description' => 'View school lifecycle state.'],
            'school.lifecycle.manage' => ['label' => 'Manage school lifecycle', 'description' => 'Suspend, reactivate or archive a school.'],
            'academic.sessions.read' => ['label' => 'Read academic periods', 'description' => 'View school academic sessions and terms.'],
            'academic.sessions.manage' => ['label' => 'Manage academic periods', 'description' => 'Create and manage school academic sessions and terms.'],
            'academic.structure.read' => ['label' => 'Read academic structure', 'description' => 'View school levels and sections.'],
            'academic.structure.manage' => ['label' => 'Manage academic structure', 'description' => 'Create and manage school levels and sections.'],
            'academic.class-arms.read' => ['label' => 'Read class arms', 'description' => 'View school class-arm configuration.'],
            'academic.class-arms.manage' => ['label' => 'Manage class arms', 'description' => 'Create and manage school class-arm configuration.'],
            'academic.subjects.read' => ['label' => 'Read subjects', 'description' => 'View the school subject catalogue.'],
            'academic.subjects.manage' => ['label' => 'Manage subjects', 'description' => 'Create and manage the school subject catalogue.'],
            'academic.offerings.read' => ['label' => 'Read subject offerings', 'description' => 'View subjects offered to school class arms and terms.'],
            'academic.offerings.manage' => ['label' => 'Manage subject offerings', 'description' => 'Create and manage subjects offered to school class arms and terms.'],
            'academic.assessment-components.read' => ['label' => 'Read assessment components', 'description' => 'View the school-defined assessment component configuration.'],
            'academic.assessment-components.manage' => ['label' => 'Manage assessment components', 'description' => 'Create and replace school-defined assessment component configuration.'],
            'academic.records.read' => ['label' => 'Read academic records', 'description' => 'View permitted academic records.'],
            'finance.records.read' => ['label' => 'Read finance records', 'description' => 'View permitted finance records.'],
            'finance.records.manage' => ['label' => 'Manage finance records', 'description' => 'Manage permitted finance operations.'],
            'counselling.private_notes.read' => ['label' => 'Read private counselling notes', 'description' => 'Read authorized private counselling notes.'],
            'academic.assignments.read' => ['label' => 'Read academic assignments', 'description' => 'Read assignments within the actor’s authorized academic scope.'],
            'academic.assignments.manage' => ['label' => 'Manage academic assignments', 'description' => 'Manage assignments within the actor’s authorized academic scope.'],
            'guardian.links.read' => ['label' => 'Read guardian links', 'description' => 'Read verified effective guardian relationships.'],
            'student.self.read' => ['label' => 'Read student self records', 'description' => 'Read the authenticated student’s permitted records.'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function rolePermissions(): array
    {
        return [
            'school_admin' => ['school.memberships.list', 'school.memberships.invite', 'school.memberships.revoke', 'school.roles.assign', 'school.roles.revoke', 'school.settings.read', 'school.settings.manage', 'school.lifecycle.read', 'school.lifecycle.manage', 'academic.sessions.read', 'academic.sessions.manage', 'academic.structure.read', 'academic.structure.manage', 'academic.class-arms.read', 'academic.class-arms.manage', 'academic.subjects.read', 'academic.subjects.manage', 'academic.offerings.read', 'academic.offerings.manage', 'academic.assessment-components.read', 'academic.assessment-components.manage', 'academic.assignments.read', 'academic.assignments.manage', 'guardian.links.read', 'student.self.read'],
            'teacher' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage'],
            'hod_reviewer' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage'],
            'principal' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage'],
            'bursar' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'finance.records.read', 'finance.records.manage'],
            'proprietor' => ['school.memberships.list', 'school.settings.read', 'school.lifecycle.read', 'school.lifecycle.manage', 'academic.sessions.read', 'academic.sessions.manage', 'academic.structure.read', 'academic.structure.manage', 'academic.class-arms.read', 'academic.class-arms.manage', 'academic.subjects.read', 'academic.subjects.manage', 'academic.offerings.read', 'academic.offerings.manage', 'academic.assessment-components.read', 'academic.assessment-components.manage', 'academic.assignments.read', 'academic.assignments.manage', 'finance.records.read'],
            'counsellor' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'counselling.private_notes.read'],
            'parent_guardian' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'guardian.links.read'],
            'student' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'student.self.read'],
        ];
    }

    /** @return array<string, array{label: string, description: string}> */
    public static function platformRoles(): array
    {
        return [
            'platform_admin' => ['label' => 'Platform Admin', 'description' => 'Manages platform authorization and approved break-glass access.'],
            'platform_support' => ['label' => 'Platform Support', 'description' => 'Uses explicitly approved, temporary support access.'],
            'platform_ops' => ['label' => 'Platform Operations', 'description' => 'Reads safe platform operations and readiness information.'],
        ];
    }

    /** @return array<string, array{label: string, description: string}> */
    public static function platformPermissions(): array
    {
        return [
            'platform.roles.manage' => ['label' => 'Manage platform roles', 'description' => 'Assign and revoke predefined platform roles.'],
            'platform.grants.create' => ['label' => 'Create break-glass grants', 'description' => 'Create approved temporary support grants.'],
            'platform.grants.revoke' => ['label' => 'Revoke break-glass grants', 'description' => 'Revoke temporary support grants.'],
            'platform.schools.read' => ['label' => 'Read platform school metadata', 'description' => 'Read approved platform-level school metadata, not school records.'],
            'platform.audit.read' => ['label' => 'Read platform audit events', 'description' => 'Read platform audit events within approved operational scope.'],
            'platform.operations.read' => ['label' => 'Read platform operations', 'description' => 'Read safe platform operations and readiness information.'],
            'platform.support.access' => ['label' => 'Use support access', 'description' => 'Use an active, approved break-glass grant within scope.'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function platformRolePermissions(): array
    {
        return [
            'platform_admin' => ['platform.roles.manage', 'platform.grants.create', 'platform.grants.revoke', 'platform.schools.read', 'platform.audit.read', 'platform.operations.read'],
            'platform_support' => ['platform.support.access'],
            'platform_ops' => ['platform.operations.read'],
        ];
    }
}
