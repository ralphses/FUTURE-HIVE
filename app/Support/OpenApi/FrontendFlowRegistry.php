<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

final class FrontendFlowRegistry
{
    /**
     * @return list<string>
     */
    public function implementedFeatureIds(): array
    {
        return array_merge(
            $this->range('FND', 10),
            $this->range('IAM', 10),
            $this->range('TEN', 10),
            $this->range('ACA', 11),
            $this->range('REG', 11),
            $this->range('IMP', 11),
            $this->range('TCH', 7),
            $this->range('ASM', 8),
            $this->range('RES', 11),
            $this->range('FIN', 16),
            $this->range('POR', 8),
            ['OPS-001', 'OPS-002', 'OPS-003', 'OPS-004', 'OPS-005', 'OPS-006', 'OPS-007', 'OPS-008', 'CNS-001', 'CNS-002', 'CNS-003', 'CNS-004', 'CNS-005', 'SAA-001', 'SAA-002', 'SAA-003', 'SAA-004', 'SAA-005', 'SAA-006', 'SAA-007', 'WEB-001', 'WEB-002', 'WEB-003', 'WEB-004', 'ADV-001', 'ADV-002', 'ADV-003', 'ADV-004', 'ADV-005', 'ADV-006'],
        );
    }

    /**
     * @param  array<string, array{operation_id: string, path: string, method: string}>  $operations
     * @return array<string, array<string, mixed>>
     */
    public function featureFlows(array $operations = []): array
    {
        $flows = [];

        foreach ($this->implementedFeatureIds() as $featureId) {
            $flows[$featureId] = $this->feature($featureId, $operations);
        }

        return $flows;
    }

    /**
     * @param  array<string, array{operation_id: string, path: string, method: string}>  $operations
     * @return array{feature_id: string, title: string, purpose: string, audience: string, entry: string, dependencies: list<string>, state_transitions: list<string>, read_write: string, states: list<string>, safe_fields: list<string>, prohibited_data: list<string>}
     */
    public function feature(string $featureId, array $operations = []): array
    {
        $featureOperations = array_keys(array_filter(
            $operations,
            fn (array $operation): bool => $this->featureForOperation($operation['operation_id']) === $featureId,
        ));

        return [
            'feature_id' => $featureId,
            'title' => $this->title($featureId),
            'purpose' => $this->purpose($featureId),
            'audience' => $this->audience($featureId),
            'entry' => $featureOperations[0] ?? 'No direct endpoint; consumed through the generated contract or supporting application workflow.',
            'dependencies' => $this->dependencies($featureId),
            'state_transitions' => $this->transitions($featureId),
            'read_write' => $this->readWrite($featureId),
            'states' => ['Loading', 'Empty', 'Validation error', 'Unauthorized/unavailable', 'Success'],
            'safe_fields' => ['Public references', 'Bounded labels and statuses', 'Server timestamps where permitted'],
            'prohibited_data' => ['Credentials', 'Tokens', 'Contacts', 'Internal IDs', 'Private URLs', 'Raw source values'],
        ];
    }

    /**
     * @param  array<string, array{path: string, method: string, operation_id: string}>  $operations
     */
    public function descriptionForOperation(string $operationId, array $operations): string
    {
        $featureId = $this->featureForOperation($operationId);
        $feature = $this->feature($featureId, $operations);
        $related = array_values(array_filter(
            $operations,
            fn (array $operation): bool => $this->featureForOperation($operation['operation_id']) === $featureId,
        ));
        usort($related, fn (array $left, array $right): int => [$left['path'], $left['method']] <=> [$right['path'], $right['method']]);

        $role = $this->role($operationId, $operations[$operationId]['method'] ?? 'GET');
        $sequence = $this->sequence($operationId, $related);
        $relatedLines = array_map(
            fn (array $operation): string => sprintf('- `%s %s` — %s', $operation['method'], $operation['path'], $this->role($operation['operation_id'], $operation['method'])),
            array_slice($related, 0, 8),
        );

        return implode("\n", [
            '',
            '### Frontend flow',
            sprintf('**Feature:** `%s` — %s', $feature['feature_id'], $feature['title']),
            sprintf('**Purpose:** %s', $feature['purpose']),
            sprintf('**Audience and authorization:** %s', $feature['audience']),
            sprintf('**This operation:** %s', $role),
            sprintf('**Sequence:** %s', $sequence),
            sprintf('**Read/write:** %s', $feature['read_write']),
            sprintf('**Dependencies:** %s', $feature['dependencies'] === [] ? 'None declared.' : implode(', ', $feature['dependencies'])),
            sprintf('**State transitions:** %s', $feature['state_transitions'] === [] ? 'Read-only projection.' : implode('; ', $feature['state_transitions'])),
            '',
            '### Related endpoints',
            ...($relatedLines === [] ? ['- This endpoint is the complete flow for this feature.'] : $relatedLines),
            '',
            '### Frontend states',
            ...array_map(fn (string $state): string => sprintf('- %s', $state), $feature['states']),
            '',
            '### Safe consumption',
            sprintf('Safe fields: %s.', implode(', ', $feature['safe_fields'])),
            sprintf('Never expect or display: %s.', implode(', ', $feature['prohibited_data'])),
            'After a successful mutation, refresh the returned public resource or status endpoint; retry only idempotent reads and mutations with the same client idempotency key where the operation contract requires one.',
        ]);
    }

    public function featureForOperation(string $operationId): string
    {
        $rules = [
            'parent-dashboard' => 'POR-001', 'student-dashboard' => 'POR-002', 'parent-attendance' => 'POR-003',
            'parent-report-cards' => 'POR-004', 'parent-fee-balances' => 'POR-005', 'parent-receipts' => 'POR-005',
            'me.profile' => 'POR-006', 'notification-preferences' => 'POR-006', 'parent-communications' => 'POR-007', 'communications.' => 'POR-007',
            'student-academic-history' => 'POR-008',
            'principal-review-dashboard' => 'OPS-001',
            'proprietor-dashboard' => 'OPS-002',
            'admin-workspace' => 'OPS-003',
            'notification-templates' => 'OPS-005', 'notifications.' => 'OPS-005',
            'audit-explorer' => 'OPS-008', 'counselling-assignments' => 'CNS-001', 'counselling-cases' => 'CNS-002', 'development-plans' => 'CNS-003', 'counselling-referrals' => 'CNS-004', 'counselling-escalations' => 'CNS-004', 'wellbeing-summaries' => 'CNS-005', 'operational-reports.export' => 'OPS-007', 'operational-report-schedules' => 'OPS-007', 'ai-evaluations' => 'ADV-006',
            'ai-consents' => 'ADV-005', 'academic-knowledge-sources' => 'ADV-004', 'platform.billing' => 'SAA-005', 'platform-billing' => 'SAA-005', 'operational-reports' => 'OPS-006', 'platform-subscription-plans' => 'SAA-001', 'platform-entitlements' => 'SAA-001', 'platform-subscription' => 'SAA-002', 'platform.operational-analytics' => 'SAA-006', 'platform.feature-flags' => 'SAA-007', 'feature-flags' => 'SAA-007', 'public.marketing-pages' => 'WEB-001', 'marketing-pages' => 'WEB-001', 'public.demo-enquiries' => 'WEB-002', 'demo-enquiries' => 'WEB-002', 'platform.schools' => 'SAA-003', 'platform.support' => 'SAA-004', 'support-cases' => 'SAA-004', 'support-access' => 'SAA-004',
            'imports.recovery' => 'IMP-008', 'imports.historical-academic' => 'IMP-009', 'imports.financial-opening-balances' => 'IMP-010',
            'imports.audit' => 'IMP-011', 'imports.report' => 'IMP-011', 'imports.dry-run' => 'IMP-006', 'imports.commit' => 'IMP-007',
            'imports.duplicates' => 'IMP-005', 'imports.validation' => 'IMP-004', 'imports.mapping' => 'IMP-003', 'imports.preview' => 'IMP-003',
            'imports.store' => 'IMP-002', 'import-templates' => 'IMP-001',
            'teacher-worklist' => 'TCH-001', 'attendance.report' => 'TCH-005', 'attendance.corrections' => 'TCH-004',
            'attendance.submit' => 'TCH-003', 'attendance.lock' => 'TCH-003', 'attendance.submission' => 'TCH-003', 'attendance.replace' => 'TCH-002',
            'attendance.show' => 'TCH-002', 'attendance.' => 'TCH-002', 'academic-insights.suggestions' => 'ADV-002', 'lesson-planning.suggestions' => 'ADV-001', 'career-guidance' => 'ADV-003', 'teaching.' => 'TCH-006', 'announcements.audiences' => 'OPS-004', 'announcements.audience' => 'OPS-004', 'announcements.' => 'TCH-007',
            'assessment-instances.access' => 'ASM-007', 'scores.audit' => 'ASM-008', 'score-imports' => 'ASM-004', 'scores.submission' => 'ASM-005',
            'scores.submit' => 'ASM-005', 'scores.' => 'ASM-002', 'assessment-instances.' => 'ASM-001',
            'grading-scales' => 'ACA-009', 'assessment-policies' => 'ACA-008', 'assessment-scheme' => 'ACA-007',
            'promotion-eligibility' => 'RES-011', 'ranking-statistics-policies' => 'RES-010', 'result-history' => 'RES-008',
            'result-exports' => 'RES-009', 'result-snapshots.corrections' => 'RES-007', 'result-corrections' => 'RES-007',
            'result-snapshots' => 'RES-005', 'result-runs.gate' => 'RES-004', 'result-runs.approve' => 'RES-004',
            'result-runs.reject' => 'RES-004', 'result-runs.publish' => 'RES-004', 'result-review-queue' => 'RES-003',
            'result-completeness' => 'RES-002', 'result-runs' => 'RES-001',
            'finance-reports' => 'FIN-014', 'financial-period-adjustments' => 'FIN-015', 'financial-period' => 'FIN-015',
            'financial-statement' => 'FIN-013', 'financial-statements' => 'FIN-013', 'payment-evidence' => 'FIN-008', 'payment-intents' => 'FIN-006',
            'payment-allocations' => 'FIN-005', 'reconciliations' => 'FIN-012', 'receipts' => 'FIN-010', 'ledger' => 'FIN-009',
            'reversals' => 'FIN-011', 'refunds' => 'FIN-011', 'fee-reminder' => 'FIN-016', 'fee-adjustment' => 'FIN-003',
            'fee-assessments' => 'FIN-002', 'invoices' => 'FIN-002', 'fee-plans' => 'FIN-001', 'fee-categories' => 'FIN-001',
            'family-billing' => 'FIN-004',
            'class-teacher-assignments' => 'REG-009', 'guardian-relationships' => 'REG-003', 'students.enrollments' => 'REG-005', 'enrollment-history' => 'REG-006',
            'promotion-cycles' => 'REG-007', 'staff.' => 'REG-008', 'students.audit' => 'REG-011', 'students.documents' => 'REG-002',
            'students.profile' => 'REG-002', 'students.search' => 'REG-010', 'students.export' => 'REG-010', 'students.' => 'REG-001',
            'guardians.' => 'REG-003', 'guardian-invitations' => 'REG-004', 'guardian-links' => 'REG-004',
            'academic-readiness' => 'ACA-011', 'academic-context' => 'ACA-001', 'academic-sessions.terms' => 'ACA-001',
            'academic-sessions' => 'ACA-001', 'academic-levels.sections.class-arms' => 'ACA-003', 'academic-levels.sections' => 'ACA-002',
            'academic-levels' => 'ACA-002', 'subjects' => 'ACA-004', 'subject-offerings.teaching-assignments' => 'ACA-006', 'subject-offerings' => 'ACA-005',
            'auth.password' => 'IAM-003', 'auth.verification' => 'IAM-004', 'auth.context' => 'IAM-010', 'auth.' => 'IAM-002', 'me.memberships' => 'IAM-005', 'memberships' => 'IAM-006', 'roles.' => 'IAM-006',
            'profile' => 'TEN-009', 'setup' => 'TEN-008', 'lifecycle' => 'TEN-010', 'context' => 'IAM-010',
            'public.school-registrations.verification' => 'TEN-003', 'public.school-registrations' => 'TEN-001', 'public.legal-policies' => 'WEB-004', 'legal-consents' => 'WEB-004', 'public.pricing' => 'WEB-003',
            'invitations' => 'IAM-005', 'files.download' => 'FND-007', 'health' => 'FND-004',
        ];

        foreach ($rules as $needle => $featureId) {
            if (str_contains($operationId, $needle)) {
                return $featureId;
            }
        }

        return 'FND-004';
    }

    /** @return list<string> */
    public function tagFeatureIds(string $tag): array
    {
        return match ($tag) {
            'Platform' => ['FND-004', 'FND-006', 'FND-007', 'FND-008', 'FND-010', 'SAA-003', 'SAA-004', 'SAA-005', 'SAA-006', 'SAA-007', 'WEB-001', 'WEB-002', 'WEB-003', 'WEB-004', 'ADV-004', 'ADV-006'],
            'Identity & Authentication' => ['IAM-001', 'IAM-002', 'IAM-003', 'IAM-004', 'IAM-009', 'IAM-010', 'WEB-004', 'ADV-005'],
            'School Registration' => ['TEN-001', 'TEN-002', 'TEN-003', 'TEN-004'],
            'School Context & Memberships' => ['IAM-005', 'IAM-006', 'IAM-007', 'TEN-005', 'TEN-006', 'TEN-008', 'TEN-009', 'TEN-010'],
            'School Administration' => ['REG-003', 'REG-004', 'REG-008', 'REG-009', 'IMP-001', 'IMP-002', 'IMP-003', 'IMP-004', 'IMP-005', 'IMP-006', 'IMP-007', 'IMP-008', 'IMP-009', 'IMP-010', 'IMP-011', 'FIN-001', 'FIN-002', 'FIN-003', 'FIN-004', 'FIN-005', 'FIN-006', 'FIN-007', 'FIN-008', 'FIN-009', 'FIN-010', 'FIN-011', 'FIN-012', 'FIN-013', 'FIN-014', 'FIN-015', 'FIN-016', 'TCH-001', 'TCH-002', 'TCH-003', 'TCH-004', 'TCH-005', 'TCH-006', 'TCH-007', 'ADV-001', 'POR-001', 'POR-003', 'POR-004', 'POR-005', 'POR-007', 'OPS-001', 'OPS-002', 'OPS-003', 'OPS-004', 'OPS-005', 'OPS-006', 'OPS-007', 'OPS-008', 'CNS-001', 'CNS-002', 'CNS-003', 'CNS-004', 'CNS-005', 'SAA-001', 'SAA-002'],
            'Academic Structure' => ['ACA-001', 'ACA-002', 'ACA-003', 'ACA-004', 'ACA-005', 'ACA-006', 'ACA-007'],
            'Academic Assessment' => ['ACA-008', 'ACA-009', 'ACA-010', 'ACA-011', 'ASM-001', 'ASM-002', 'ASM-003', 'ASM-004', 'ASM-005', 'ASM-006', 'ASM-007', 'ASM-008', 'RES-001', 'RES-002', 'RES-003', 'RES-004', 'RES-005', 'RES-006', 'RES-007', 'RES-008', 'RES-009', 'RES-010', 'RES-011'],
            'Student Registry' => ['REG-001', 'REG-002', 'REG-005', 'REG-006', 'REG-007', 'REG-010', 'REG-011', 'POR-002', 'POR-006', 'POR-008', 'ADV-003'],
            default => [],
        };
    }

    private function role(string $operationId, string $method): string
    {
        if (str_contains($operationId, 'show') || str_ends_with($operationId, '.index') || $method === 'GET') {
            return 'List/read step';
        }

        if (str_contains($operationId, 'activate') || str_contains($operationId, 'approve') || str_contains($operationId, 'publish') || str_contains($operationId, 'submit') || str_contains($operationId, 'close') || str_contains($operationId, 'reject') || str_contains($operationId, 'cancel') || str_contains($operationId, 'retire') || str_contains($operationId, 'apply') || str_contains($operationId, 'revoke')) {
            return 'Lifecycle transition';
        }

        return in_array($method, ['POST', 'PUT', 'PATCH'], true) ? 'Create/update step' : 'Flow entry';
    }

    /** @param list<array{path: string, method: string, operation_id: string}> $related */
    private function sequence(string $operationId, array $related): string
    {
        if ($related === []) {
            return 'Load authenticated context, call this endpoint, then render the safe response.';
        }

        $position = array_search($operationId, array_column($related, 'operation_id'), true);
        $position = $position === false ? 1 : $position + 1;

        return sprintf('Step %d of %d: load the required context, call this endpoint, then refresh the returned resource/status when a mutation succeeds.', $position, count($related));
    }

    private function title(string $featureId): string
    {
        return sprintf('%s frontend flow', $featureId);
    }

    private function purpose(string $featureId): string
    {
        return match ($featureId) {
            'OPS-008' => 'Explores school-scoped audit history through bounded filters and redacted public event projections.',
            'CNS-001' => 'Assigns active counsellors to specific students while exposing only scoped assignment metadata.',
            'CNS-002' => 'Provides confidential counselling case metadata and private immutable notes only within an active counsellor assignment.',
            'CNS-003' => 'Provides explicitly shared, non-confidential development-plan summaries without exposing private counselling notes.',
            'CNS-004' => 'Routes authorized counselling referrals and safeguarding escalations through explicit internal scopes without exposing private notes.',
            'CNS-005' => 'Provides bounded wellbeing summaries from counselling metadata without exposing private notes or inferred diagnoses.',
            'SAA-001' => 'Defines versioned platform subscription plans and effective school entitlement keys without pricing, billing or payment behavior.',
            'SAA-002' => 'Manages school subscription trials, active access, grace periods, expiry, renewal and cancellation without pricing, billing or payment behavior.',
            'SAA-003' => 'Provides safe platform tenant directory projections for school lifecycle, academic context, setup, subscription and entitlement status.',
            'SAA-004' => 'Manages bounded platform support cases and temporary, explicitly approved, auditable school access grants.',
            'SAA-005' => 'Provides platform SaaS invoice and provider-neutral payment records from active subscriptions without mutating school-fee finance or entitlements.',
            'SAA-006' => 'Provides aggregate platform operational analytics from safe lifecycle, subscription, setup and support projections without exposing sensitive source data.',
            'SAA-007' => 'Manages allowlisted versioned platform feature flags with fail-closed effective school values and no authorization side effects.',
            'WEB-001' => 'Publishes approved benefits, FAQs and product copy without exposing tenant data or inventing customer metrics.',
            'WEB-002' => 'Accepts bounded public demo and contact enquiries without provisioning identities, schools or tenant context.',
            'WEB-003' => 'Publishes approved plan labels, entitlement summaries and availability metadata without exposing prices, billing state or school records.',
            'WEB-004' => 'Publishes approved legal-policy versions and records exact self-service consent without exposing private identity or audit data.',
            'ADV-001' => 'Provides bounded, unreviewed lesson-planning suggestions from trusted academic context without persisting AI output or changing school records.',
            'ADV-002' => 'Provides bounded, explainable and unreviewed academic insight suggestions from authorized published/approved projections without automated penalties or academic mutations.',
            'ADV-003' => 'Provides safe career and subject guidance from approved, versioned requirements without making admissions guarantees or mutating academic records.',
            'ADV-004' => 'Governs versioned JAMB and institution requirement sources so student guidance uses only approved, current and bounded source projections.',
            'ADV-005' => 'Gates sensitive AI-assisted academic requests through exact, revocable consent and minimized provider payloads without persisting generated content.',
            'ADV-006' => 'Evaluates approved AI features with deterministic sanitized fixtures and human-reviewed release gates for accuracy, bias, refusal, hallucination and privacy safety.',
            default => sprintf('Consumes the implemented %s contract through safe public references and server-controlled state.', $featureId),
        };
    }

    private function audience(string $featureId): string
    {
        return match ($featureId) {
            'OPS-008' => 'Principal, proprietor or school admin with active membership and operations.audit.read in the trusted school context.',
            'CNS-001' => 'School admin or principal manages assignments; an active counsellor reads only their own assignments in the trusted school context.',
            'CNS-002' => 'Assigned active counsellors manage their cases and notes; school admin and principal receive case metadata only, never private notes by default.',
            'CNS-003' => 'Assigned active counsellors manage plans; approved school leadership receives only the permitted shared-plan projection in the trusted school context.',
            'CNS-004' => 'Assigned active counsellors may request referrals; school admin and principal approve or reject through maker-checker authorization.',
            'CNS-005' => 'Assigned active counsellors read their assigned student summaries; approved safeguarding leadership reads permitted school-scoped summaries.',
            'SAA-001' => 'School admins and proprietors manage configuration through trusted school context; authorized school members read effective entitlements.',
            'SAA-002' => 'School admins and proprietors manage lifecycle state through trusted school context; authorized school members read safe subscription status and effective entitlements.',
            'SAA-003' => 'Platform administrators with platform.schools.read use the platform authorization context; school membership context is not accepted as authority.',
            'SAA-004' => 'Approved platform operations staff use platform authorization to manage support cases; temporary school access requires explicit maker-checker approval and expiry.',
            'SAA-005' => 'Platform operations and authorized school billing roles manage SaaS invoices and provider-neutral payment state for an active subscription; school-fee finance remains separate.',
            'SAA-006' => 'Approved platform operations use platform.analytics.read independently of school membership; school users and portal identities are denied.',
            'SAA-007' => 'Approved platform administrators and operations staff use platform.feature-flags.read/manage; school users and portal identities cannot manage platform configuration.',
            'WEB-001' => 'Unauthenticated callers consume only approved, published public content; no school membership or identity context is required.',
            'WEB-002' => 'Unauthenticated public callers submit bounded enquiries; acknowledgement is generic and does not reveal contact or delivery state.',
            'WEB-003' => 'Unauthenticated callers consume only approved published plan metadata; no school membership, identity or selected-school context is required.',
            'WEB-004' => 'Unauthenticated callers read published legal policies; authenticated identities accept exact published versions through their self-service boundary.',
            'ADV-001' => 'Active assigned teachers and approved academic leadership request suggestions within the trusted school context; provider output is untrusted draft content.',
            'ADV-002' => 'Active assigned teachers and approved academic leadership request explainable suggestions within their trusted academic scope; output requires human review.',
            'ADV-003' => 'Authenticated students use their active self-service student linkage; guidance is informational, source-backed and never an admissions decision.',
            'ADV-004' => 'Approved platform administrators and academic-governance operators manage source versions; student and school users receive no governance access.',
            'ADV-005' => 'Authenticated students and authorized academic callers use self-service AI consent status; provider calls remain behind the server privacy boundary.',
            'ADV-006' => 'Approved platform operations and independent platform reviewers use platform authorization; school membership is not accepted as authority.',
            default => str_starts_with($featureId, 'POR-') ? 'Authenticated student or verified guardian, limited to self or active verified relationships.' : 'Authenticated callers with the permission and trusted school/session context required by the endpoint.',
        };
    }

    private function readWrite(string $featureId): string
    {
        return in_array($featureId, ['FND-004', 'FND-007', 'FND-008', 'FND-010', 'ACA-011', 'RES-001', 'RES-002', 'RES-003', 'RES-008', 'RES-009', 'RES-011', 'TCH-001', 'TCH-005', 'IMP-011', 'FIN-004', 'FIN-013', 'FIN-014', 'POR-001', 'POR-002', 'POR-003', 'POR-004', 'POR-005', 'POR-008', 'OPS-001', 'OPS-002', 'OPS-003', 'OPS-006', 'OPS-008', 'SAA-003', 'SAA-006', 'WEB-001', 'WEB-003', 'ADV-002', 'ADV-003'], true) ? 'Read-only projection or export.' : 'Read and/or server-authorized mutation according to the operation.';
    }

    /** @return list<string> */
    private function dependencies(string $featureId): array
    {
        return match ($featureId) {
            'POR-008' => ['RES-008'],
            'OPS-001' => ['RES-002', 'RES-003', 'FIN-012', 'TCH-005', 'IMP-011'],
            'OPS-002' => ['OPS-001'],
            'OPS-003' => ['TEN-008', 'TEN-010', 'REG-008', 'IAM-006', 'ACA-001'],
            'OPS-004' => ['TCH-007', 'IAM-006', 'TEN-010'],
            'OPS-005' => ['OPS-004', 'FIN-016', 'IAM-004', 'TEN-010'],
            'OPS-007' => ['OPS-006', 'FND-007'],
            'OPS-008' => ['FND-008', 'IAM-006'],
            'SAA-001' => ['IAM-006', 'TEN-010'],
            'SAA-002' => ['SAA-001', 'IAM-006', 'TEN-010'],
            'SAA-003' => ['SAA-001', 'SAA-002', 'IAM-006', 'TEN-010'],
            'SAA-004' => ['SAA-003', 'IAM-006', 'TEN-010'],
            'SAA-005' => ['SAA-001', 'SAA-002', 'IAM-006', 'TEN-010'],
            'SAA-006' => ['SAA-003', 'SAA-004', 'SAA-005', 'IAM-006'],
            'SAA-007' => ['SAA-001', 'SAA-002', 'SAA-003', 'IAM-006'],
            'WEB-001' => ['FND-004'],
            'WEB-002' => ['FND-004', 'TEN-001'],
            'WEB-003' => ['SAA-001', 'SAA-002'],
            'WEB-004' => ['FND-004', 'IAM-002', 'IAM-004'],
            'ADV-001' => ['ACA-005', 'ACA-006', 'TCH-006'],
            'ADV-002' => ['RES-008', 'TCH-005', 'ASM-005', 'ADV-001'],
            'ADV-003' => ['ACA-004', 'RES-008', 'POR-008'],
            'ADV-004' => ['ADV-003'],
            'ADV-005' => ['WEB-004', 'ADV-001', 'ADV-002', 'ADV-003', 'ADV-004'],
            'ADV-006' => ['ADV-001', 'ADV-002', 'ADV-003', 'ADV-004', 'ADV-005'],
            'CNS-001' => ['IAM-006', 'IAM-007', 'REG-001', 'REG-005'],
            'CNS-002' => ['CNS-001', 'IAM-006', 'IAM-007', 'REG-001', 'REG-005'],
            'CNS-003' => ['CNS-001', 'CNS-002', 'IAM-006', 'IAM-007', 'REG-001', 'REG-005'],
            'CNS-004' => ['CNS-001', 'CNS-002', 'CNS-003', 'IAM-006', 'IAM-007', 'REG-001', 'REG-005'],
            'CNS-005' => ['CNS-001', 'CNS-002', 'CNS-003', 'CNS-004', 'IAM-006', 'IAM-007', 'REG-001', 'REG-005'],
            'POR-004' => ['RES-005', 'RES-006'],
            'POR-005' => ['FIN-013', 'FIN-010'],
            'RES-011' => ['ACA-010', 'RES-005'],
            'IMP-008' => ['IMP-007'],
            default => [],
        };
    }

    /** @return list<string> */
    private function transitions(string $featureId): array
    {
        return match ($featureId) {
            'TCH-003' => ['draft → submitted → locked'],
            'ASM-001' => ['draft → open → closed or cancelled'],
            'RES-004' => ['pending → approved/rejected → published'],
            'FIN-001' => ['draft → active → retired'],
            'CNS-001' => ['active → revoked'],
            'CNS-002' => ['open → on_hold', 'on_hold → closed', 'open → closed'],
            'CNS-003' => ['draft → active → closed → archived'],
            'CNS-004' => ['requested → approved/rejected', 'approved → accepted → closed', 'requested/approved → cancelled'],
            'SAA-001' => ['draft → active → retired', 'school entitlement: active → revoked'],
            'SAA-002' => ['trial → active → grace → expired', 'trial/active/grace → cancelled', 'expired → grace', 'active/grace → renewed lifecycle'],
            'SAA-003' => [],
            'SAA-004' => ['open → in_progress → waiting_on_school → resolved → closed', 'requested → approved → revoked or expired', 'requested → rejected/cancelled'],
            'SAA-005' => ['draft → issued → paid/overdue/voided', 'pending → completed/failed/cancelled/refunded'],
            'SAA-007' => ['draft → active → retired', 'effective value: school override → platform default → disabled'],
            'WEB-002' => ['received → triaged → resolved → closed', 'received → spam/cancelled'],
            'WEB-004' => ['draft → published → retired', 'published policy → accepted consent'],
            'ADV-001' => ['authorized academic context → unreviewed suggestion'],
            'ADV-002' => ['authorized published/approved projections → unreviewed insight suggestion'],
            'ADV-003' => ['approved source context → unreviewed guidance or guidance_unavailable'],
            'ADV-004' => ['draft → published → retired', 'draft → rejected', 'published source → guidance projection or guidance_unavailable'],
            'ADV-005' => ['requested → active → expired', 'active → revoked', 'missing/expired/revoked → consent_required or provider_unavailable'],
            'ADV-006' => ['draft → running → completed or blocked', 'pending → approved or rejected', 'failed thresholds → blocked release gate'],
            default => [],
        };
    }

    /** @return list<string> */
    private function range(string $prefix, int $maximum): array
    {
        return array_map(fn (int $number): string => sprintf('%s-%03d', $prefix, $number), range(1, $maximum));
    }
}
