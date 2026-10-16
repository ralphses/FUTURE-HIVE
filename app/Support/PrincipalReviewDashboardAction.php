<?php

declare(strict_types=1);

namespace App\Support;

use App\Contexts\Academic\Application\Actions\AcademicResultCompletenessAction;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceRegister;
use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicResultEntry;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Finance\Domain\Models\FinanceReconciliationItem;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;

class PrincipalReviewDashboardAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly AcademicResultCompletenessAction $completeness,
    ) {}

    /** @param array{session?: string|null, term?: string|null} $filters
     * @return array<string, mixed>
     */
    public function execute(UserIdentity $actor, string $school, array $filters = []): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'operations.principal-dashboard.read')) {
            throw new ModelNotFoundException;
        }

        [$session, $term] = $this->academicContext($context->schoolId, $filters);

        return [
            'id' => 'principal-review-dashboard',
            'request_id' => Context::get('request_id'),
            'school_id' => $school,
            'session_id' => $session?->public_id,
            'term_id' => $term?->public_id,
            'sections' => [
                'academic' => $this->academicSection($actor, $school, $context->schoolId, $session, $term),
                'finance' => $this->financeSection($school, $context->schoolId),
                'attendance' => $this->attendanceSection($school, $context->schoolId, $term),
                'imports' => $this->importSection($school, $context->schoolId),
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    /** @param array{session?: string|null, term?: string|null} $filters
     * @return array{0: ?AcademicSession, 1: ?AcademicTerm}
     */
    private function academicContext(int $schoolId, array $filters): array
    {
        $sessionQuery = AcademicSession::query()->where('school_id', $schoolId)->where('status', 'active');
        if (isset($filters['session'])) {
            $sessionQuery->where('public_id', $filters['session']);
        }
        $session = $sessionQuery->orderBy('id')->first();
        if (! $session instanceof AcademicSession) {
            if (isset($filters['session']) || isset($filters['term'])) {
                throw new ModelNotFoundException;
            }

            return [null, null];
        }

        $termQuery = AcademicTerm::query()->where('school_id', $schoolId)->where('academic_session_id', $session->getKey())->where('status', 'active');
        if (isset($filters['term'])) {
            $termQuery->where('public_id', $filters['term']);
        }
        $term = $termQuery->orderBy('sequence')->orderBy('id')->first();
        if (! $term instanceof AcademicTerm && (isset($filters['term']) || isset($filters['session']))) {
            throw new ModelNotFoundException;
        }

        return [$session, $term];
    }

    /** @return array<string, mixed> */
    private function academicSection(UserIdentity $actor, string $school, int $schoolId, ?AcademicSession $session, ?AcademicTerm $term): array
    {
        if (! $session instanceof AcademicSession || ! $term instanceof AcademicTerm) {
            return ['pending_review_count' => 0, 'blocked_incomplete_count' => 0, 'issue_codes' => ['ACADEMIC_CONTEXT_UNAVAILABLE'], 'detail_references' => [], 'last_updated_at' => null];
        }

        $runs = AcademicResultRun::query()->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->whereIn('status', ['completed', 'blocked']);
        $pending = (clone $runs)->where('status', 'completed')->where('not_computable_count', 0)->count();
        $blocked = (clone $runs)->where(function ($query): void {
            $query->where('status', 'blocked')->orWhere('not_computable_count', '>', 0);
        })->count();
        $runIds = (clone $runs)->pluck('id');
        $issueCodes = $runIds->isEmpty() ? [] : AcademicResultEntry::query()->whereIn('academic_result_run_id', $runIds)->whereNotNull('error_code')->distinct()->orderBy('error_code')->pluck('error_code')->all();
        $completeness = $this->completeness->execute($actor, $school, (string) $session->public_id, (string) $term->public_id);
        $issueCodes = collect($issueCodes)->merge(collect($completeness['checks'])->pluck('error_code')->filter())->unique()->sort()->values()->all();

        return ['pending_review_count' => $pending, 'blocked_incomplete_count' => $blocked + collect($completeness['checks'])->where('status', '!=', 'ready')->count(), 'issue_codes' => $issueCodes, 'detail_references' => [
            ['rel' => 'result_review_queue', 'path' => "/api/v1/schools/{$school}/academic-sessions/{$session->public_id}/terms/{$term->public_id}/result-review-queue"],
            ['rel' => 'result_completeness', 'path' => "/api/v1/schools/{$school}/academic-sessions/{$session->public_id}/terms/{$term->public_id}/result-completeness"],
        ], 'last_updated_at' => (clone $runs)->latest('updated_at')->first()?->updated_at?->toISOString()];
    }

    /** @return array<string, mixed> */
    private function financeSection(string $school, int $schoolId): array
    {
        $exceptions = FinanceReconciliationItem::query()->where('school_id', $schoolId)->where('status', 'exception');

        return ['unresolved_reconciliation_exception_count' => $exceptions->count(), 'issue_codes' => $exceptions->whereNotNull('exception_code')->distinct()->orderBy('exception_code')->pluck('exception_code')->all(), 'detail_references' => [['rel' => 'reconciliation', 'path' => "/api/v1/schools/{$school}/reconciliations"]], 'last_updated_at' => $exceptions->latest('updated_at')->first()?->updated_at?->toISOString()];
    }

    /** @return array<string, mixed> */
    private function attendanceSection(string $school, int $schoolId, ?AcademicTerm $term): array
    {
        if (! $term instanceof AcademicTerm) {
            return ['missing_register_count' => 0, 'issue_codes' => ['ACADEMIC_CONTEXT_UNAVAILABLE'], 'detail_references' => [], 'last_updated_at' => null];
        }
        $classArmCount = AcademicClassArm::query()->where('school_id', $schoolId)->where('status', 'active')->count();
        $covered = AcademicAttendanceRegister::query()->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->whereIn('status', ['submitted', 'locked'])->distinct('academic_class_arm_id')->count('academic_class_arm_id');
        $missing = max(0, $classArmCount - $covered);

        return ['missing_register_count' => $missing, 'issue_codes' => $missing > 0 ? ['ATTENDANCE_REGISTER_MISSING'] : [], 'detail_references' => [['rel' => 'attendance_reports', 'path' => "/api/v1/schools/{$school}/academic-sessions/{$term->academic_session_id}/terms/{$term->public_id}/attendance-report"]], 'last_updated_at' => AcademicAttendanceRegister::query()->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->latest('updated_at')->first()?->updated_at?->toISOString()];
    }

    /** @return array<string, mixed> */
    private function importSection(string $school, int $schoolId): array
    {
        $failed = ImportBatch::query()->where('school_id', $schoolId)->whereIn('status', ['failed', 'completed_with_errors']);

        return ['failed_or_recoverable_count' => $failed->count(), 'issue_codes' => $failed->exists() ? ['IMPORT_RECOVERY_REQUIRED'] : [], 'detail_references' => [['rel' => 'import_audit', 'path' => "/api/v1/schools/{$school}/imports"]], 'last_updated_at' => $failed->latest('updated_at')->first()?->updated_at?->toISOString()];
    }
}
