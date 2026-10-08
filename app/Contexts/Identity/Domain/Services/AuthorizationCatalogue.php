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
            'academic.records.read' => ['label' => 'Read academic records', 'description' => 'View permitted academic records.'],
            'finance.records.read' => ['label' => 'Read finance records', 'description' => 'View permitted finance records.'],
            'finance.records.manage' => ['label' => 'Manage finance records', 'description' => 'Manage permitted finance operations.'],
            'counselling.private_notes.read' => ['label' => 'Read private counselling notes', 'description' => 'Read authorized private counselling notes.'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function rolePermissions(): array
    {
        return [
            'school_admin' => ['school.memberships.list', 'school.memberships.invite', 'school.memberships.revoke', 'school.roles.assign', 'school.roles.revoke', 'school.settings.read', 'school.settings.manage'],
            'teacher' => ['school.memberships.list', 'academic.records.read'],
            'hod_reviewer' => ['school.memberships.list', 'academic.records.read'],
            'principal' => ['school.memberships.list', 'academic.records.read', 'school.settings.read'],
            'bursar' => ['school.memberships.list', 'finance.records.read', 'finance.records.manage'],
            'proprietor' => ['school.memberships.list', 'school.settings.read', 'finance.records.read'],
            'counsellor' => ['school.memberships.list', 'counselling.private_notes.read'],
            'parent_guardian' => ['school.memberships.list'],
            'student' => ['school.memberships.list'],
        ];
    }
}
