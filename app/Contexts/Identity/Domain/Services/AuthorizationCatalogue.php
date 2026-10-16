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
            'academic.assessments.read' => ['label' => 'Read assessment instances', 'description' => 'View assessment instances in the selected school.'],
            'academic.assessments.manage' => ['label' => 'Manage assessment instances', 'description' => 'Create and manage assessment instances in the selected school.'],
            'academic.scores.read' => ['label' => 'Read draft scores', 'description' => 'View draft scores for authorized assessment instances in the selected school.'],
            'academic.scores.manage' => ['label' => 'Manage draft scores', 'description' => 'Create and update draft scores for authorized assessment instances in the selected school.'],
            'academic.scores.submit' => ['label' => 'Submit assessment scores', 'description' => 'Submit a complete set of draft scores for an authorized assessment instance.'],
            'academic.scores.submission.read' => ['label' => 'Read score submissions', 'description' => 'View safe score-submission status for authorized assessment instances.'],
            'academic.scores.review' => ['label' => 'Review score submissions', 'description' => 'Review submitted scores within the selected school.'],
            'academic.scores.return' => ['label' => 'Return score submissions', 'description' => 'Return submitted scores for correction with a bounded reason.'],
            'academic.scores.approve' => ['label' => 'Approve score submissions', 'description' => 'Approve submitted scores and make them immutable.'],
            'academic.scores.resubmit' => ['label' => 'Resubmit returned scores', 'description' => 'Resubmit corrected scores after a submission was returned.'],
            'academic.scores.audit.read' => ['label' => 'Read score audit history', 'description' => 'View safe immutable score-change history within the selected academic scope.'],
            'academic.results.compute' => ['label' => 'Compute academic results', 'description' => 'Generate school-scoped result projections from approved assessment submissions.'],
            'academic.results.read' => ['label' => 'Read academic results', 'description' => 'Read safe school-scoped result projections.'],
            'academic.results.completeness.read' => ['label' => 'Read result completeness', 'description' => 'Read safe term-scoped result completeness checks.'],
            'academic.results.completeness.manage' => ['label' => 'Manage result completeness', 'description' => 'Run server-controlled result completeness checks.'],
            'academic.results.review.read' => ['label' => 'Read result review queue', 'description' => 'View safe result runs awaiting academic leadership review.'],
            'academic.results.review.manage' => ['label' => 'Manage result review queue', 'description' => 'Use server-controlled result review actions when enabled.'],
            'academic.results.approve' => ['label' => 'Approve academic results', 'description' => 'Approve complete result runs for publication gating.'],
            'academic.results.publish' => ['label' => 'Publish academic results', 'description' => 'Publish approved result runs through the server-controlled gate.'],
            'academic.results.snapshots.read' => ['label' => 'Read result snapshots', 'description' => 'Read immutable school-scoped published result snapshots.'],
            'academic.results.snapshots.create' => ['label' => 'Create result snapshots', 'description' => 'Create an immutable snapshot from a published result run.'],
            'academic.results.history.read' => ['label' => 'Read student result history', 'description' => 'Read safe published result history in the selected academic scope.'],
            'academic.results.exports.read' => ['label' => 'Export academic results', 'description' => 'Download safe CSV or PDF exports of published results within the selected academic scope.'],
            'academic.results.templates.read' => ['label' => 'Read report-card templates', 'description' => 'Read school-scoped report-card template definitions.'],
            'academic.results.templates.manage' => ['label' => 'Manage report-card templates', 'description' => 'Create and lifecycle-manage school-scoped report-card templates.'],
            'academic.results.statistics.read' => ['label' => 'Read ranking and statistics policies', 'description' => 'Read school-scoped ranking and statistics policy configuration.'],
            'academic.results.statistics.manage' => ['label' => 'Manage ranking and statistics policies', 'description' => 'Create and lifecycle-manage school-scoped ranking and statistics policy versions.'],
            'academic.results.promotion-eligibility.read' => ['label' => 'Read promotion eligibility', 'description' => 'Read safe, derived promotion-eligibility summaries for the selected school.'],
            'academic.assessment-access.read' => ['label' => 'Read assessment access windows', 'description' => 'View assessment score-entry access windows in the selected school.'],
            'academic.assessment-access.manage' => ['label' => 'Manage assessment access windows', 'description' => 'Create, activate and close assessment score-entry access windows.'],
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
            'finance.fee-plans.read' => ['label' => 'Read fee categories and plans', 'description' => 'View school-scoped fee categories and versioned fee plans.'],
            'finance.fee-plans.manage' => ['label' => 'Manage fee categories and plans', 'description' => 'Create and lifecycle-manage school-scoped fee categories and versioned fee plans.'],
            'finance.fee-assessments.read' => ['label' => 'Read fee assessments', 'description' => 'View school-scoped fee assessment runs and their safe counts.'],
            'finance.fee-assessments.manage' => ['label' => 'Manage fee assessments', 'description' => 'Generate and finalize school-scoped fee assessments from active plans.'],
            'finance.invoices.read' => ['label' => 'Read invoices', 'description' => 'View safe school-scoped invoices generated from fee assessments.'],
            'finance.invoices.manage' => ['label' => 'Manage invoices', 'description' => 'Issue school-scoped invoices generated from fee assessments.'],
            'finance.adjustments.read' => ['label' => 'Read invoice adjustments', 'description' => 'View safe discounts, scholarships and waivers within the selected school.'],
            'finance.adjustments.manage' => ['label' => 'Manage invoice adjustments', 'description' => 'Request and manage draft-invoice discounts, scholarships and waivers.'],
            'finance.adjustments.approve' => ['label' => 'Approve invoice adjustments', 'description' => 'Approve or reject bounded invoice adjustment requests.'],
            'finance.adjustments.apply' => ['label' => 'Apply invoice adjustments', 'description' => 'Apply approved adjustments to draft invoices without posting ledger activity.'],
            'finance.family-billing.read' => ['label' => 'Read family billing', 'description' => 'View safe consolidated billing for authorized family relationships.'],
            'finance.family-billing.manage' => ['label' => 'Manage family billing', 'description' => 'Reserved for future family billing management workflows.'],
            'finance.payment-allocations.read' => ['label' => 'Read payment allocations', 'description' => 'View safe partial-payment allocations within the selected school.'],
            'finance.payment-allocations.manage' => ['label' => 'Manage payment allocations', 'description' => 'Allocate approved integer minor-unit payments against issued school invoices.'],
            'finance.payment-intents.read' => ['label' => 'Read payment intents', 'description' => 'View safe provider-agnostic payment intents within the selected school.'],
            'finance.payment-intents.manage' => ['label' => 'Manage payment intents', 'description' => 'Create and cancel provider-agnostic payment intents for issued invoices.'],
            'finance.payment-evidence.read' => ['label' => 'Read payment evidence', 'description' => 'View safe manual payment evidence within the selected school.'],
            'finance.payment-evidence.manage' => ['label' => 'Manage payment evidence', 'description' => 'Record and cancel manual payment evidence for issued invoices.'],
            'finance.payment-evidence.review' => ['label' => 'Review payment evidence', 'description' => 'Verify or reject manual payment evidence without posting settlement activity.'],
            'finance.ledger.read' => ['label' => 'Read financial ledger', 'description' => 'View safe school-scoped financial ledger batches and entries.'],
            'finance.ledger.manage' => ['label' => 'Manage financial ledger', 'description' => 'Create and post balanced school-scoped financial ledger batches.'],
            'finance.receipts.read' => ['label' => 'Read receipts', 'description' => 'View safe immutable receipts within the selected school.'],
            'finance.receipts.manage' => ['label' => 'Manage receipts', 'description' => 'Issue receipts for verified school payment sources.'],
            'finance.reversals.read' => ['label' => 'Read reversals', 'description' => 'View safe compensating reversal requests in the selected school.'],
            'finance.reversals.manage' => ['label' => 'Manage reversals', 'description' => 'Request compensating reversals for eligible posted financial sources.'],
            'finance.reversals.approve' => ['label' => 'Approve reversals', 'description' => 'Approve, reject or apply compensating reversals with maker-checker separation.'],
            'finance.refunds.read' => ['label' => 'Read refunds', 'description' => 'View safe compensating refund requests in the selected school.'],
            'finance.refunds.manage' => ['label' => 'Manage refunds', 'description' => 'Request compensating refunds for eligible verified payment sources.'],
            'finance.refunds.approve' => ['label' => 'Approve refunds', 'description' => 'Approve, reject or apply compensating refunds with maker-checker separation.'],
            'finance.reconciliation.read' => ['label' => 'Read financial reconciliation', 'description' => 'View safe school-scoped reconciliation runs and exceptions.'],
            'finance.reconciliation.manage' => ['label' => 'Manage financial reconciliation', 'description' => 'Create and cancel deterministic school-scoped reconciliation runs.'],
            'finance.reconciliation.resolve' => ['label' => 'Resolve reconciliation exceptions', 'description' => 'Resolve eligible reconciliation exceptions with a bounded reason.'],
            'operations.principal-dashboard.read' => ['label' => 'Read principal review dashboard', 'description' => 'View safe school-wide operational review indicators without exposing source records or financial amounts.'],
            'operations.proprietor-dashboard.read' => ['label' => 'Read proprietor executive dashboard', 'description' => 'View safe school-wide executive indicators without exposing source records or financial amounts.'],
            'finance.balances.read' => ['label' => 'Read outstanding balances', 'description' => 'View safe outstanding financial balances in the selected school.'],
            'finance.statements.read' => ['label' => 'Read financial statements', 'description' => 'View safe student and family financial statements in the selected school.'],
            'finance.reports.read' => ['label' => 'Read finance reports', 'description' => 'View safe school-scoped bursar finance reports.'],
            'finance.reports.export' => ['label' => 'Export finance reports', 'description' => 'Download bounded safe bursar finance reports.'],
            'finance.periods.read' => ['label' => 'Read financial periods', 'description' => 'View safe school-scoped financial close status.'],
            'finance.periods.manage' => ['label' => 'Manage financial periods', 'description' => 'Start and complete controlled financial period closes.'],
            'finance.periods.reopen' => ['label' => 'Reopen financial periods', 'description' => 'Reopen eligible closed periods with an audited reason.'],
            'finance.post-close-adjustments.read' => ['label' => 'Read post-close adjustments', 'description' => 'View safe post-close adjustment requests.'],
            'finance.post-close-adjustments.manage' => ['label' => 'Manage post-close adjustments', 'description' => 'Request post-close compensating adjustments.'],
            'finance.post-close-adjustments.approve' => ['label' => 'Approve post-close adjustments', 'description' => 'Approve, reject or apply post-close adjustments.'],
            'finance.reminders.read' => ['label' => 'Read fee reminders', 'description' => 'View safe school-scoped fee reminder candidates and statuses.'],
            'finance.reminders.manage' => ['label' => 'Manage fee reminders', 'description' => 'Create and cancel school-scoped fee reminders.'],
            'finance.reminders.send' => ['label' => 'Send fee reminders', 'description' => 'Send eligible fee reminders through the approved delivery contract.'],
            'counselling.private_notes.read' => ['label' => 'Read private counselling notes', 'description' => 'Read authorized private counselling notes.'],
            'counselling.assignments.read' => ['label' => 'Read counsellor assignments', 'description' => 'Read safe counsellor assignment metadata within the selected school.'],
            'counselling.assignments.manage' => ['label' => 'Manage counsellor assignments', 'description' => 'Assign and revoke counsellor-to-student access within the selected school.'],
            'counselling.development-plans.read' => ['label' => 'Read development plans', 'description' => 'View explicitly shared development plan summaries within authorized counselling scope.'],
            'counselling.development-plans.manage' => ['label' => 'Manage development plans', 'description' => 'Create and manage development plan summaries within authorized counselling scope.'],
            'counselling.development-plans.share' => ['label' => 'Share development plans', 'description' => 'Share development plan summaries through server-controlled counselling scopes.'],
            'counselling.referrals.read' => ['label' => 'Read counselling referrals', 'description' => 'View safe referral metadata within authorized counselling scope.'],
            'counselling.referrals.manage' => ['label' => 'Manage counselling referrals', 'description' => 'Create and lifecycle-manage authorized counselling referrals.'],
            'counselling.referrals.approve' => ['label' => 'Approve counselling referrals', 'description' => 'Approve or reject counselling referrals with maker-checker separation.'],
            'counselling.escalations.read' => ['label' => 'Read safeguarding escalations', 'description' => 'View safe escalation metadata within authorized safeguarding scope.'],
            'counselling.escalations.manage' => ['label' => 'Manage safeguarding escalations', 'description' => 'Create and lifecycle-manage authorized safeguarding escalations.'],
            'counselling.escalations.approve' => ['label' => 'Approve safeguarding escalations', 'description' => 'Approve or reject safeguarding escalations with maker-checker separation.'],
            'counselling.wellbeing-summaries.read' => ['label' => 'Read wellbeing summaries', 'description' => 'View safe derived wellbeing summaries within authorized counselling scope.'],
            'counselling.cases.read' => ['label' => 'Read counselling cases', 'description' => 'Read authorized counselling case metadata within the selected school.'],
            'counselling.cases.manage' => ['label' => 'Manage counselling cases', 'description' => 'Create and lifecycle-manage authorized counselling cases.'],
            'counselling.notes.read' => ['label' => 'Read counselling notes', 'description' => 'Read private notes only within an active counselling assignment.'],
            'counselling.notes.manage' => ['label' => 'Manage counselling notes', 'description' => 'Create immutable private counselling notes within an active assignment.'],
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
            'communications.parent.read' => ['label' => 'Read parent communications', 'description' => 'Read verified-guardian announcements and communication threads.'],
            'communications.parent.manage' => ['label' => 'Manage parent communications', 'description' => 'Open and participate in verified-guardian communication threads.'],
            'communications.staff.read' => ['label' => 'Read staff communications', 'description' => 'Read communication threads within the trusted staff assignment scope.'],
            'communications.staff.manage' => ['label' => 'Manage staff communications', 'description' => 'Respond to and close communication threads within the trusted staff scope.'],
            'operations.notification-templates.read' => ['label' => 'Read notification templates', 'description' => 'View versioned school-scoped notification templates.'],
            'operations.notification-templates.manage' => ['label' => 'Manage notification templates', 'description' => 'Create and lifecycle-manage school-scoped notification templates.'],
            'operations.notifications.read' => ['label' => 'Read notifications', 'description' => 'View safe school-scoped notification delivery states.'],
            'operations.notifications.manage' => ['label' => 'Manage notifications', 'description' => 'Create and cancel authorized notification deliveries.'],
            'operations.notifications.send' => ['label' => 'Send notifications', 'description' => 'Send notifications through the approved provider-neutral delivery contract.'],
            'operations.reports.read' => ['label' => 'Read operational reports', 'description' => 'View safe school-scoped operational reporting projections.'],
            'operations.reports.export' => ['label' => 'Export operational reports', 'description' => 'Generate bounded private operational report artifacts.'],
            'operations.reports.schedules.read' => ['label' => 'Read operational report schedules', 'description' => 'View safe operational report schedule configuration.'],
            'operations.reports.schedules.manage' => ['label' => 'Manage operational report schedules', 'description' => 'Create and manage school-scoped operational report schedules.'],
            'operations.audit.read' => ['label' => 'Read school audit explorer', 'description' => 'View safe school-scoped audit history projections.'],
            'platform.subscription-plans.read' => ['label' => 'Read subscription plans', 'description' => 'View safe platform subscription plan versions and entitlement keys.'],
            'platform.subscription-plans.manage' => ['label' => 'Manage subscription plans', 'description' => 'Create and lifecycle-manage configuration-only platform subscription plans.'],
            'platform.entitlements.read' => ['label' => 'Read school entitlements', 'description' => 'View effective entitlement keys for the trusted school.'],
            'platform.entitlements.manage' => ['label' => 'Manage school entitlements', 'description' => 'Assign an active platform plan to the trusted school.'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function rolePermissions(): array
    {
        $permissions = [
            'school_admin' => ['school.memberships.list', 'school.memberships.invite', 'school.memberships.revoke', 'school.roles.assign', 'school.roles.revoke', 'school.settings.read', 'school.settings.manage', 'school.lifecycle.read', 'school.lifecycle.manage', 'academic.sessions.read', 'academic.sessions.manage', 'academic.structure.read', 'academic.structure.manage', 'academic.class-arms.read', 'academic.class-arms.manage', 'academic.subjects.read', 'academic.subjects.manage', 'academic.offerings.read', 'academic.offerings.manage', 'academic.assessment-components.read', 'academic.assessment-components.manage', 'academic.assessment-policies.read', 'academic.assessment-policies.manage', 'academic.grading-scales.read', 'academic.grading-scales.manage', 'academic.promotion-rules.read', 'academic.promotion-rules.manage', 'academic.readiness.read', 'students.read', 'students.admit', 'students.manage', 'students.profile.read', 'students.profile.manage', 'students.documents.read', 'students.documents.manage', 'students.enrollments.read', 'students.enrollments.manage', 'students.enrollments.transfer', 'students.enrollments.withdraw', 'students.promotion.read', 'students.promotion.manage', 'students.promotion.approve', 'students.promotion.apply', 'academic.assignments.read', 'academic.assignments.manage', 'guardians.read', 'guardians.manage', 'guardian.links.read', 'guardian.links.manage', 'student.self.read', 'staff.read', 'staff.manage', 'staff.employment.manage', 'staff.class-teachers.read', 'staff.class-teachers.manage', 'counselling.assignments.read', 'counselling.assignments.manage', 'counselling.cases.read', 'counselling.cases.manage', 'counselling.development-plans.read', 'counselling.development-plans.manage', 'counselling.development-plans.share'],
            'teacher' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage', 'staff.class-teachers.read'],
            'hod_reviewer' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage', 'staff.class-teachers.read'],
            'principal' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'students.promotion.read', 'students.promotion.approve', 'students.promotion.apply', 'academic.records.read', 'academic.assignments.read', 'academic.assignments.manage', 'guardians.read', 'guardian.links.read', 'staff.read', 'staff.class-teachers.read', 'counselling.assignments.read', 'counselling.assignments.manage', 'counselling.cases.read', 'counselling.cases.manage', 'counselling.development-plans.read', 'counselling.development-plans.manage', 'counselling.development-plans.share'],
            'bursar' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'finance.records.read', 'finance.records.manage', 'staff.class-teachers.read'],
            'proprietor' => ['school.memberships.list', 'school.settings.read', 'school.lifecycle.read', 'school.lifecycle.manage', 'academic.sessions.read', 'academic.sessions.manage', 'academic.structure.read', 'academic.structure.manage', 'academic.class-arms.read', 'academic.class-arms.manage', 'academic.subjects.read', 'academic.subjects.manage', 'academic.offerings.read', 'academic.offerings.manage', 'academic.assessment-components.read', 'academic.assessment-components.manage', 'academic.assessment-policies.read', 'academic.assessment-policies.manage', 'academic.grading-scales.read', 'academic.grading-scales.manage', 'academic.promotion-rules.read', 'academic.promotion-rules.manage', 'academic.readiness.read', 'students.read', 'students.admit', 'students.manage', 'students.profile.read', 'students.profile.manage', 'students.documents.read', 'students.documents.manage', 'students.enrollments.transfer', 'students.enrollments.withdraw', 'students.promotion.read', 'students.promotion.manage', 'students.promotion.approve', 'students.promotion.apply', 'academic.assignments.read', 'academic.assignments.manage', 'guardians.read', 'guardians.manage', 'guardian.links.read', 'guardian.links.manage', 'finance.records.read', 'staff.read', 'staff.manage', 'staff.employment.manage', 'staff.class-teachers.read', 'staff.class-teachers.manage'],
            'counsellor' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'students.profile.read', 'students.documents.read', 'guardians.read', 'guardian.links.read', 'counselling.private_notes.read', 'counselling.assignments.read', 'counselling.cases.read', 'counselling.cases.manage', 'counselling.notes.read', 'counselling.notes.manage', 'counselling.development-plans.read', 'counselling.development-plans.manage', 'counselling.development-plans.share', 'staff.read', 'staff.class-teachers.read'],
            'parent_guardian' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'guardian.links.read', 'finance.family-billing.read', 'communications.parent.read', 'communications.parent.manage'],
            'student' => ['school.memberships.list', 'academic.sessions.read', 'academic.structure.read', 'academic.class-arms.read', 'academic.subjects.read', 'academic.offerings.read', 'academic.assessment-components.read', 'academic.assessment-policies.read', 'academic.grading-scales.read', 'academic.promotion-rules.read', 'academic.readiness.read', 'students.read', 'student.self.read'],
        ];

        foreach (['school_admin', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'communications.staff.read';
            $permissions[$role][] = 'communications.staff.manage';
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
            $permissions[$role][] = 'operations.notification-templates.read';
            $permissions[$role][] = 'operations.notification-templates.manage';
            $permissions[$role][] = 'operations.notifications.read';
            $permissions[$role][] = 'operations.notifications.manage';
            $permissions[$role][] = 'operations.notifications.send';
        }

        foreach (['teacher', 'hod_reviewer'] as $role) {
            $permissions[$role][] = 'communications.staff.read';
            $permissions[$role][] = 'communications.staff.manage';
        }

        foreach (['school_admin', 'proprietor', 'bursar'] as $role) {
            $permissions[$role][] = 'finance.fee-plans.read';
            $permissions[$role][] = 'finance.fee-plans.manage';
            $permissions[$role][] = 'finance.fee-assessments.read';
            $permissions[$role][] = 'finance.invoices.read';
        }

        foreach (['school_admin', 'proprietor', 'bursar'] as $role) {
            $permissions[$role][] = 'finance.fee-assessments.manage';
            $permissions[$role][] = 'finance.invoices.manage';
            $permissions[$role][] = 'finance.adjustments.read';
            $permissions[$role][] = 'finance.adjustments.manage';
            $permissions[$role][] = 'finance.adjustments.approve';
            $permissions[$role][] = 'finance.adjustments.apply';
            $permissions[$role][] = 'finance.family-billing.read';
            $permissions[$role][] = 'finance.family-billing.manage';
            $permissions[$role][] = 'finance.payment-allocations.read';
            $permissions[$role][] = 'finance.payment-allocations.manage';
            $permissions[$role][] = 'finance.payment-intents.read';
            $permissions[$role][] = 'finance.payment-intents.manage';
            $permissions[$role][] = 'finance.payment-evidence.read';
            $permissions[$role][] = 'finance.payment-evidence.manage';
            $permissions[$role][] = 'finance.payment-evidence.review';
            $permissions[$role][] = 'finance.ledger.read';
            $permissions[$role][] = 'finance.ledger.manage';
            $permissions[$role][] = 'finance.receipts.read';
            $permissions[$role][] = 'finance.receipts.manage';
            $permissions[$role][] = 'finance.reversals.read';
            $permissions[$role][] = 'finance.reversals.manage';
            $permissions[$role][] = 'finance.reversals.approve';
            $permissions[$role][] = 'finance.refunds.read';
            $permissions[$role][] = 'finance.refunds.manage';
            $permissions[$role][] = 'finance.refunds.approve';
            $permissions[$role][] = 'finance.reconciliation.read';
            $permissions[$role][] = 'finance.reconciliation.manage';
            $permissions[$role][] = 'finance.reconciliation.resolve';
            $permissions[$role][] = 'finance.balances.read';
            $permissions[$role][] = 'finance.statements.read';
            $permissions[$role][] = 'finance.reports.read';
            $permissions[$role][] = 'finance.reports.export';
            $permissions[$role][] = 'finance.periods.read';
            $permissions[$role][] = 'finance.periods.manage';
            $permissions[$role][] = 'finance.post-close-adjustments.read';
            $permissions[$role][] = 'finance.post-close-adjustments.manage';
            $permissions[$role][] = 'finance.post-close-adjustments.approve';
            $permissions[$role][] = 'finance.reminders.read';
            $permissions[$role][] = 'finance.reminders.manage';
            $permissions[$role][] = 'finance.reminders.send';
        }

        foreach (['school_admin', 'proprietor'] as $role) {
            $permissions[$role][] = 'finance.periods.reopen';
        }

        foreach (['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'teaching.worklist.read';
        }

        foreach (['school_admin', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'operations.principal-dashboard.read';
            $permissions[$role][] = 'operations.reports.read';
            $permissions[$role][] = 'operations.reports.export';
            $permissions[$role][] = 'operations.reports.schedules.read';
            $permissions[$role][] = 'operations.reports.schedules.manage';
            $permissions[$role][] = 'operations.audit.read';
        }

        foreach (['school_admin', 'proprietor'] as $role) {
            $permissions[$role][] = 'platform.subscription-plans.read';
            $permissions[$role][] = 'platform.subscription-plans.manage';
            $permissions[$role][] = 'platform.entitlements.read';
            $permissions[$role][] = 'platform.entitlements.manage';
        }

        $permissions['proprietor'][] = 'operations.proprietor-dashboard.read';

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

        foreach (['school_admin', 'principal', 'counsellor'] as $role) {
            $permissions[$role][] = 'counselling.referrals.read';
            $permissions[$role][] = 'counselling.referrals.manage';
            $permissions[$role][] = 'counselling.escalations.read';
            $permissions[$role][] = 'counselling.escalations.manage';
            $permissions[$role][] = 'counselling.wellbeing-summaries.read';
        }

        foreach (['school_admin', 'principal'] as $role) {
            $permissions[$role][] = 'counselling.referrals.approve';
            $permissions[$role][] = 'counselling.escalations.approve';
        }

        foreach (array_keys(self::roles()) as $role) {
            $permissions[$role][] = 'academic.assessments.read';
        }
        foreach (['school_admin', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.assessments.manage';
        }
        foreach (array_keys(self::roles()) as $role) {
            $permissions[$role][] = 'academic.scores.read';
        }
        foreach (['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.scores.manage';
        }
        foreach (['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.results.read';
            $permissions[$role][] = 'academic.results.completeness.read';
        }
        foreach (['school_admin', 'hod_reviewer', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.results.compute';
            $permissions[$role][] = 'academic.results.completeness.manage';
            $permissions[$role][] = 'academic.results.review.read';
            $permissions[$role][] = 'academic.results.review.manage';
        }
        foreach (['school_admin', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.results.approve';
        }
        foreach (['principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.results.publish';
        }
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] as $role) {
            $permissions[$role][] = 'academic.results.snapshots.read';
        }
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] as $role) {
            $permissions[$role][] = 'academic.results.history.read';
        }
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] as $role) {
            $permissions[$role][] = 'academic.results.exports.read';
        }
        foreach (['proprietor', 'principal'] as $role) {
            $permissions[$role][] = 'academic.results.snapshots.create';
        }
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] as $role) {
            $permissions[$role][] = 'academic.results.templates.read';
        }
        foreach (['school_admin', 'proprietor', 'principal'] as $role) {
            $permissions[$role][] = 'academic.results.templates.manage';
        }
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] as $role) {
            $permissions[$role][] = 'academic.results.statistics.read';
        }
        foreach (['school_admin', 'proprietor', 'principal'] as $role) {
            $permissions[$role][] = 'academic.results.statistics.manage';
        }
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer'] as $role) {
            $permissions[$role][] = 'academic.results.promotion-eligibility.read';
        }
        foreach (array_keys(self::roles()) as $role) {
            $permissions[$role][] = 'academic.scores.submission.read';
        }
        foreach (['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.scores.submit';
        }
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer'] as $role) {
            $permissions[$role][] = 'academic.scores.review';
            $permissions[$role][] = 'academic.scores.return';
            $permissions[$role][] = 'academic.scores.approve';
        }
        foreach (['school_admin', 'teacher', 'proprietor', 'principal', 'hod_reviewer'] as $role) {
            $permissions[$role][] = 'academic.scores.resubmit';
        }
        foreach (array_keys(self::roles()) as $role) {
            $permissions[$role][] = 'academic.scores.audit.read';
        }
        foreach (array_keys(self::roles()) as $role) {
            $permissions[$role][] = 'academic.assessment-access.read';
        }
        foreach (['school_admin', 'proprietor'] as $role) {
            $permissions[$role][] = 'academic.assessment-access.manage';
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
            'platform.analytics.read' => ['label' => 'Read platform operational analytics', 'description' => 'Read aggregate platform operational analytics without sensitive source data.'],
            'platform.feature-flags.read' => ['label' => 'Read platform feature flags', 'description' => 'Read safe platform feature-flag projections.'],
            'platform.feature-flags.manage' => ['label' => 'Manage platform feature flags', 'description' => 'Manage versioned platform feature-flag configuration.'],
            'academic.knowledge-sources.read' => ['label' => 'Read academic knowledge sources', 'description' => 'Read safe versioned JAMB and institution requirement sources.'],
            'academic.knowledge-sources.manage' => ['label' => 'Manage academic knowledge sources', 'description' => 'Manage governed academic knowledge-source versions.'],
            'platform.support.access' => ['label' => 'Use support access', 'description' => 'Use an active, approved break-glass grant within scope.'],
            'platform.support-cases.read' => ['label' => 'Read support cases', 'description' => 'View safe platform support-case metadata.'],
            'platform.support-cases.manage' => ['label' => 'Manage support cases', 'description' => 'Create and lifecycle-manage platform support cases.'],
            'platform.support-access.read' => ['label' => 'Read support access grants', 'description' => 'View safe, scoped support-access grant metadata.'],
            'platform.support-access.request' => ['label' => 'Request support access', 'description' => 'Request bounded, expiring support access for an approved case.'],
            'platform.support-access.approve' => ['label' => 'Approve support access', 'description' => 'Approve support access with maker-checker authorization.'],
            'platform.support-access.revoke' => ['label' => 'Revoke support access', 'description' => 'Revoke active support access grants.'],
            'platform.billing.read' => ['label' => 'Read SaaS billing', 'description' => 'View safe platform SaaS invoices and payment state.'],
            'platform.billing.manage' => ['label' => 'Manage SaaS billing', 'description' => 'Create, issue and void platform SaaS invoices.'],
            'platform.billing.payments.manage' => ['label' => 'Initiate SaaS payments', 'description' => 'Create provider-neutral SaaS payment records.'],
            'platform.billing.payments.verify' => ['label' => 'Verify SaaS payments', 'description' => 'Verify signed SaaS payment outcomes and replay protection.'],
            'platform.billing.refunds.manage' => ['label' => 'Manage SaaS refunds', 'description' => 'Record approved SaaS payment refunds.'],
            'platform.ai-evaluations.read' => ['label' => 'Read AI evaluations', 'description' => 'Read safe AI evaluation and release-gate projections.'],
            'platform.ai-evaluations.manage' => ['label' => 'Manage AI evaluations', 'description' => 'Create and run bounded synthetic AI evaluations.'],
            'platform.ai-evaluations.review' => ['label' => 'Review AI evaluations', 'description' => 'Approve or reject human-reviewed AI evaluation release gates.'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function platformRolePermissions(): array
    {
        return [
            'platform_admin' => ['platform.roles.manage', 'platform.grants.create', 'platform.grants.revoke', 'platform.schools.read', 'platform.audit.read', 'platform.operations.read', 'platform.analytics.read', 'platform.feature-flags.read', 'platform.feature-flags.manage', 'academic.knowledge-sources.read', 'academic.knowledge-sources.manage', 'platform.support-cases.read', 'platform.support-cases.manage', 'platform.support-access.read', 'platform.support-access.request', 'platform.support-access.approve', 'platform.support-access.revoke', 'platform.billing.read', 'platform.billing.manage', 'platform.billing.payments.manage', 'platform.billing.payments.verify', 'platform.billing.refunds.manage', 'platform.ai-evaluations.read', 'platform.ai-evaluations.manage', 'platform.ai-evaluations.review'],
            'platform_support' => ['platform.support.access', 'platform.support-cases.read', 'platform.support-access.read', 'platform.support-access.request'],
            'platform_ops' => ['platform.operations.read', 'platform.analytics.read', 'platform.feature-flags.read', 'platform.feature-flags.manage', 'academic.knowledge-sources.read', 'academic.knowledge-sources.manage', 'platform.support-cases.read', 'platform.support-cases.manage', 'platform.support-access.read', 'platform.support-access.request', 'platform.billing.read', 'platform.billing.manage', 'platform.billing.payments.manage', 'platform.billing.payments.verify', 'platform.billing.refunds.manage', 'platform.ai-evaluations.read', 'platform.ai-evaluations.manage', 'platform.ai-evaluations.review'],
        ];
    }
}
