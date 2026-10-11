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
            'academic.assessment-policies.read' => ['label' => 'Read assessment policies', 'description' => 'View immutable assessment policy versions for subject offerings.'],
            'academic.assessment-policies.manage' => ['label' => 'Manage assessment policies', 'description' => 'Create, activate and retire assessment policy versions.'],
            'academic.grading-scales.read' => ['label' => 'Read grading scales', 'description' => 'View grading scale versions and remarks for assessment policies.'],
            'academic.grading-scales.manage' => ['label' => 'Manage grading scales', 'description' => 'Create, activate and retire grading scale versions.'],
            'academic.promotion-rules.read' => ['label' => 'Read promotion rules', 'description' => 'View school-defined level-to-level promotion criteria.'],
            'academic.promotion-rules.manage' => ['label' => 'Manage promotion rules', 'description' => 'Create and manage school-defined promotion criteria.'],
            'academic.readiness.read' => ['label' => 'Read academic readiness', 'description' => 'Check whether academic configuration is ready for future assessment workflows.'],
            'students.read' => ['label' => 'Read students', 'description' => 'View students in the selected school.'],
            'students.export' => ['label' => 'Export students', 'description' => 'Export permitted student registry fields from the selected school.'],
            'imports.templates.read' => ['label' => 'Read import templates', 'description' => 'View and download approved versioned import templates for the selected school.'],
            'imports.upload' => ['label' => 'Upload imports', 'description' => 'Upload approved CSV or Excel files for staging in the selected school.'],
            'imports.read' => ['label' => 'Read imports', 'description' => 'View import staging metadata for the selected school.'],
            'imports.cancel' => ['label' => 'Cancel imports', 'description' => 'Cancel an uploaded or staged import in the selected school.'],
            'imports.mapping.read' => ['label' => 'Read import mappings', 'description' => 'View saved column mappings and safe import previews.'],
            'imports.mapping.manage' => ['label' => 'Manage import mappings', 'description' => 'Create a new immutable column mapping for a staged import.'],
            'imports.validation.read' => ['label' => 'Read import validation', 'description' => 'View row validation results and normalized previews.'],
            'imports.validation.manage' => ['label' => 'Validate imports', 'description' => 'Run validation and normalization over a mapped staged import.'],
            'imports.duplicates.read' => ['label' => 'Read import duplicates', 'description' => 'View safe duplicate candidates for a validated staged import.'],
            'imports.duplicates.manage' => ['label' => 'Manage import duplicates', 'description' => 'Detect and explicitly resolve duplicate candidates before later import steps.'],
            'imports.dry-run.read' => ['label' => 'Read import dry-run reports', 'description' => 'View safe impact reports for staged imports.'],
            'imports.dry-run.generate' => ['label' => 'Generate import dry-run reports', 'description' => 'Analyze validated imports without changing canonical school records.'],
            'imports.commit.read' => ['label' => 'Read import commits', 'description' => 'View safe results for controlled import commits in the selected school.'],
            'imports.commit.generate' => ['label' => 'Commit imports', 'description' => 'Create validated, explicitly resolved import records in the selected school.'],
            'imports.recovery.read' => ['label' => 'Read import recovery', 'description' => 'View safe retry results for partial import commits in the selected school.'],
            'imports.recovery.execute' => ['label' => 'Recover import failures', 'description' => 'Retry failed rows from a partial import commit without duplicating successful rows.'],
            'imports.historical-academic.read' => ['label' => 'Read historical academic imports', 'description' => 'View safe historical academic import status in the selected school.'],
            'imports.historical-academic.manage' => ['label' => 'Manage historical academic imports', 'description' => 'Archive approved historical academic values without changing current academic results.'],
            'imports.financial-opening-balances.read' => ['label' => 'Read financial opening-balance imports', 'description' => 'View safe historical opening-balance import status in the selected school.'],
            'imports.financial-opening-balances.manage' => ['label' => 'Manage financial opening-balance imports', 'description' => 'Archive unverified opening balances without creating payments or ledger transactions.'],
            'imports.audit.read' => ['label' => 'Read import audit history', 'description' => 'View safe audit history for import processing stages in the selected school.'],
            'imports.report.read' => ['label' => 'Download import reports', 'description' => 'Download bounded safe audit reports for import processing in the selected school.'],
            'students.audit.read' => ['label' => 'Read student audit history', 'description' => 'View safe history of sensitive student changes in the selected school.'],
            'students.admit' => ['label' => 'Admit students', 'description' => 'Create student admission records in the selected school.'],
            'students.manage' => ['label' => 'Manage students', 'description' => 'Update and manage student admission lifecycle in the selected school.'],
            'students.profile.read' => ['label' => 'Read student profiles', 'description' => 'View approved student profile information in the selected school.'],
            'students.profile.manage' => ['label' => 'Manage student profiles', 'description' => 'Create and update student profile information in the selected school.'],
            'students.documents.read' => ['label' => 'Read student documents', 'description' => 'View approved student document metadata and signed downloads.'],
            'students.documents.manage' => ['label' => 'Manage student documents', 'description' => 'Upload and revoke student documents in the selected school.'],
            'students.enrollments.read' => ['label' => 'Read student enrollments', 'description' => 'View term and class-arm enrollments in the selected school.'],
            'students.enrollments.manage' => ['label' => 'Manage student enrollments', 'description' => 'Place active students into approved class arms and end enrollments.'],
            'students.enrollments.transfer' => ['label' => 'Transfer student enrollments', 'description' => 'Move active enrollments to another available class arm in the same school and term.'],
            'students.enrollments.withdraw' => ['label' => 'Withdraw student enrollments', 'description' => 'End active student placements for an administrative withdrawal reason.'],
            'students.promotion.read' => ['label' => 'Read promotion cycles', 'description' => 'View human-approved promotion and repetition cycles.'],
            'students.promotion.manage' => ['label' => 'Manage promotion cycles', 'description' => 'Create cycles and propose explicit student decisions.'],
            'students.promotion.approve' => ['label' => 'Approve promotion cycles', 'description' => 'Approve proposed student promotion decisions.'],
            'students.promotion.apply' => ['label' => 'Apply promotion cycles', 'description' => 'Apply approved placement decisions transactionally.'],
            'academic.records.read' => ['label' => 'Read academic records', 'description' => 'View permitted academic records.'],
            'finance.records.read' => ['label' => 'Read finance records', 'description' => 'View permitted finance records.'],
            'finance.records.manage' => ['label' => 'Manage finance records', 'description' => 'Manage permitted finance operations.'],
            'counselling.private_notes.read' => ['label' => 'Read private counselling notes', 'description' => 'Read authorized private counselling notes.'],
            'academic.assignments.read' => ['label' => 'Read academic assignments', 'description' => 'Read assignments within the actor’s authorized academic scope.'],
            'academic.assignments.manage' => ['label' => 'Manage academic assignments', 'description' => 'Manage assignments within the actor’s authorized academic scope.'],
            'guardian.links.read' => ['label' => 'Read guardian links', 'description' => 'Read verified effective guardian relationships.'],
            'guardian.links.manage' => ['label' => 'Manage guardian links', 'description' => 'Create, update and revoke school-scoped guardian relationships.'],
            'guardians.read' => ['label' => 'Read guardians', 'description' => 'View approved guardian profile information in the selected school.'],
            'guardians.manage' => ['label' => 'Manage guardians', 'description' => 'Create and update approved guardian profile information.'],
            'guardians.audit.read' => ['label' => 'Read guardian audit history', 'description' => 'View safe history of guardian relationship and invitation changes in the selected school.'],
            'student.self.read' => ['label' => 'Read student self records', 'description' => 'Read the authenticated student’s permitted records.'],
            'staff.read' => ['label' => 'Read staff profiles', 'description' => 'View staff profiles and employment state in the selected school.'],
            'staff.manage' => ['label' => 'Manage staff profiles', 'description' => 'Create and update profiles for existing school members.'],
            'staff.employment.manage' => ['label' => 'Manage staff employment', 'description' => 'Activate, suspend or end staff employment in the selected school.'],
            'staff.class-teachers.read' => ['label' => 'Read class-teacher assignments', 'description' => 'View class-teacher assignments in the selected school.'],
            'staff.class-teachers.manage' => ['label' => 'Manage class-teacher assignments', 'description' => 'Assign and revoke class teachers for school class arms.'],
            'teaching.worklist.read' => ['label' => 'Read teacher worklist', 'description' => 'View the authenticated teacher’s assigned classes and subject offerings.'],
            'attendance.read' => ['label' => 'Read attendance', 'description' => 'View daily attendance for authorized class arms.'],
            'attendance.manage' => ['label' => 'Manage attendance', 'description' => 'Record daily attendance for authorized class arms.'],
            'attendance.reports.read' => ['label' => 'Read attendance reports', 'description' => 'View aggregate attendance summaries for authorized class arms.'],
            'teaching.schemes.read' => ['label' => 'Read schemes of work', 'description' => 'View school-owned schemes and topic progress.'],
            'teaching.schemes.manage' => ['label' => 'Manage schemes of work', 'description' => 'Create and manage school-owned schemes and topic progress.'],
            'teaching.lessons.read' => ['label' => 'Read lesson records', 'description' => 'View lesson records within the trusted school scope.'],
            'teaching.lessons.manage' => ['label' => 'Manage lesson records', 'description' => 'Create and edit assigned teacher lesson drafts.'],
            'teaching.lessons.review' => ['label' => 'Review lesson records', 'description' => 'Approve or return submitted lesson records.'],
            'teaching.announcements.read' => ['label' => 'Read teacher announcements', 'description' => 'Read announcements within the trusted school audience scope.'],
            'teaching.announcements.manage' => ['label' => 'Manage teacher announcements', 'description' => 'Create and manage announcements for assigned academic audiences.'],
            'teaching.announcements.review' => ['label' => 'Review teacher announcements', 'description' => 'Approve or reject submitted announcements.'],
            'teaching.announcements.publish' => ['label' => 'Publish teacher announcements', 'description' => 'Publish or recall approved school announcements.'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function rolePermissions(): array
    {
        $permissions = [
            'school_admin' => ['school.memberships.list', 'school.memberships.invite', 'school.memberships.revoke', 'school.roles.assign', 'school.roles.revoke', 'school.settings.read', 'school.settings.manage', 'school.lifecycle.read', 'school.lifecycle.manage', 'academic.sessions.read', 'academic.sessions.manage', 'academic.structure.read', 'academic.structure.manage', 'academic.class-arms.read', 'academic.class-arms.manage', 'academic.subjects.read', 'academic.subjects.manage', 'academic.offerings.read', 'academic.offerings.manage', 'academic.assessment-components.read', 'academic.assessment-components.manage', 'academic.assessment-policies.read', 'academic.assessment-policies.manage', 'academic.grading-scales.read', 'academic.grading-scales.manage', 'academic.promotion-rules.read', 'academic.promotion-rules.manage', 'academic.readiness.read', 'students.read', 'students.admit', 'students.manage', 'students.profile.read', 'students.profile.manage', 'students.documents.read', 'students.documents.manage', 'students.enrollments.read', 'students.enrollments.manage', 'students.enrollments.transfer', 'students.enrollments.withdraw', 'students.promotion.read', 'students.promotion.manage', 'students.promotion.approve', 'students.promotion.apply', 'academic.assignments.read', 'academic.assignments.manage', 'guardians.read', 'guardians.manage', 'guardian.links.read', 'guardian.links.manage', 'student.self.read', 'staff.read', 'staff.manage', 'staff.employment.manage', 'staff.class-teachers.read', 'staff.class-teachers.manage'],
            'teacher' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage', 'staff.class-teachers.read'],
            'hod_reviewer' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage', 'staff.class-teachers.read'],
            'principal' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'students.promotion.read', 'students.promotion.approve', 'students.promotion.apply', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage', 'guardians.read', 'guardian.links.read', 'staff.read', 'staff.class-teachers.read'],
            'bursar' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'finance.records.read', 'finance.records.manage', 'staff.class-teachers.read'],
            'proprietor' => ['school.memberships.list', 'school.settings.read', 'school.lifecycle.read', 'school.lifecycle.manage', 'academic.sessions.read', 'academic.sessions.manage', 'academic.structure.read', 'academic.structure.manage', 'academic.class-arms.read', 'academic.class-arms.manage', 'academic.subjects.read', 'academic.subjects.manage', 'academic.offerings.read', 'academic.offerings.manage', 'academic.assessment-components.read', 'academic.assessment-components.manage', 'academic.assessment-policies.read', 'academic.assessment-policies.manage', 'academic.grading-scales.read', 'academic.grading-scales.manage', 'academic.promotion-rules.read', 'academic.promotion-rules.manage', 'academic.readiness.read', 'students.read', 'students.admit', 'students.manage', 'students.profile.read', 'students.profile.manage', 'students.documents.read', 'students.documents.manage', 'students.enrollments.transfer', 'students.enrollments.withdraw', 'students.promotion.read', 'students.promotion.manage', 'students.promotion.approve', 'students.promotion.apply', 'academic.assignments.read', 'academic.assignments.manage', 'guardians.read', 'guardians.manage', 'guardian.links.read', 'guardian.links.manage', 'finance.records.read', 'staff.read', 'staff.manage', 'staff.employment.manage', 'staff.class-teachers.read', 'staff.class-teachers.manage'],
            'counsellor' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'guardians.read', 'guardian.links.read', 'counselling.private_notes.read', 'staff.read', 'staff.class-teachers.read'],
            'parent_guardian' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'guardian.links.read'],
            'student' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'student.self.read'],
        ];

        foreach (['school_admin', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'students.export';
            $permissions[$role][] = 'imports.templates.read';
            $permissions[$role][] = 'imports.upload';
            $permissions[$role][] = 'imports.read';
            $permissions[$role][] = 'imports.cancel';
            $permissions[$role][] = 'imports.mapping.read';
            $permissions[$role][] = 'imports.mapping.manage';
            $permissions[$role][] = 'imports.validation.read';
            $permissions[$role][] = 'imports.validation.manage';
            $permissions[$role][] = 'imports.duplicates.read';
            $permissions[$role][] = 'imports.duplicates.manage';
            $permissions[$role][] = 'imports.dry-run.read';
            $permissions[$role][] = 'imports.dry-run.generate';
            $permissions[$role][] = 'imports.commit.read';
            $permissions[$role][] = 'imports.commit.generate';
            $permissions[$role][] = 'imports.recovery.read';
            $permissions[$role][] = 'imports.recovery.execute';
            $permissions[$role][] = 'imports.historical-academic.read';
            $permissions[$role][] = 'imports.historical-academic.manage';
            $permissions[$role][] = 'imports.financial-opening-balances.read';
            $permissions[$role][] = 'imports.financial-opening-balances.manage';
            $permissions[$role][] = 'imports.audit.read';
            $permissions[$role][] = 'imports.report.read';
            $permissions[$role][] = 'students.audit.read';
            $permissions[$role][] = 'guardians.audit.read';
        }

        foreach (['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'teaching.worklist.read';
        }

        foreach (['school_admin', 'teacher', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'attendance.read';
            $permissions[$role][] = 'attendance.manage';
            $permissions[$role][] = 'attendance.reports.read';
        }

        foreach (['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'teaching.schemes.read';
            $permissions[$role][] = 'teaching.lessons.read';
            $permissions[$role][] = 'teaching.announcements.read';
        }

        foreach (['school_admin', 'teacher', 'principal', 'proprietor', 'hod_reviewer'] as $role) {
            $permissions[$role][] = 'teaching.schemes.manage';
            $permissions[$role][] = 'teaching.lessons.review';
        }

        foreach (['school_admin', 'teacher', 'principal', 'proprietor', 'hod_reviewer'] as $role) {
            $permissions[$role][] = 'teaching.lessons.manage';
            $permissions[$role][] = 'teaching.announcements.manage';
        }

        foreach (['school_admin', 'principal', 'proprietor', 'hod_reviewer'] as $role) {
            $permissions[$role][] = 'teaching.announcements.review';
            $permissions[$role][] = 'teaching.announcements.publish';
        }

        return $permissions;
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
