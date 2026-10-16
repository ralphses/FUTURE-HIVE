<?php

declare(strict_types=1);

namespace App\Support;

use App\Contexts\Academic\Domain\Models\AcademicAttendanceEntry;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceRegister;
use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FinanceReconciliationItem;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OperationalReportingAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @param array{session?: string|null, term?: string|null, from?: string|null, to?: string|null, per_page?: int|null} $filters
     * @return array<string, mixed>
     */
    public function execute(Authenticatable $actor, string $school, string $kind, array $filters = []): array
    {
        $context = TenantContext::require();
        $schoolStatus = (string) DB::table('schools')->where('id', $context->schoolId)->value('status');
        if ($context->schoolPublicId !== $school || $schoolStatus !== 'active' || ! $this->permissions->allows($actor, $school, 'operations.reports.read')) {
            throw new ModelNotFoundException;
        }

        $this->validatePeriod($filters);
        $academic = $this->academicContext($context->schoolId, $filters);
        $session = $academic['session'];
        $term = $academic['term'];
        $report = match ($kind) {
            'enrollment' => $this->enrollment($context->schoolId, $term?->getKey(), $filters),
            'attendance' => $this->attendance($context->schoolId, $term?->getKey(), $filters),
            'academic' => $this->academic($context->schoolId, $term?->getKey(), $filters),
            'finance' => $this->finance($context->schoolId, $term?->getKey(), $filters),
            default => throw new ModelNotFoundException,
        };

        $this->audit->execute(new AuditEventData(
            action: 'operations.report_read',
            subjectType: 'operational_report_projection',
            subjectPublicId: null,
            schoolId: $context->schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['report' => $kind, 'result_count' => (int) ($report['result_count'] ?? 0)],
            requiresSchoolContext: true,
        ));

        return [
            'id' => 'operational-report-'.$kind,
            'request_id' => Context::get('request_id'),
            'school_id' => $school,
            'session_id' => $session?->public_id,
            'term_id' => $term?->public_id,
            'date_range' => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null],
            'report' => $report,
            'generated_at' => now()->toISOString(),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array{session: ?AcademicSession, term: ?AcademicTerm}
     */
    private function academicContext(int $schoolId, array $filters): array
    {
        $sessionQuery = AcademicSession::query()->where('school_id', $schoolId)->where('status', 'active');
        if (isset($filters['session'])) {
            $sessionQuery->where('public_id', (string) $filters['session']);
        }
        $session = $sessionQuery->orderBy('start_date')->orderBy('id')->first();
        if (! $session instanceof AcademicSession) {
            if (isset($filters['session'], $filters['term'])) {
                throw new ModelNotFoundException;
            }

            return ['session' => null, 'term' => null];
        }

        $termQuery = AcademicTerm::query()->where('school_id', $schoolId)->where('academic_session_id', $session->getKey())->where('status', 'active');
        if (isset($filters['term'])) {
            $termQuery->where('public_id', (string) $filters['term']);
        }
        $term = $termQuery->orderBy('sequence')->orderBy('id')->first();
        if (isset($filters['term']) && ! $term instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return ['session' => $session, 'term' => $term];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function enrollment(int $schoolId, ?int $termId, array $filters): array
    {
        if ($termId === null) {
            return ['active_enrollment_count' => 0, 'placement_count' => 0, 'denominator' => 0, 'issue_codes' => ['ACADEMIC_CONTEXT_UNAVAILABLE'], 'result_count' => 0, 'last_updated_at' => null];
        }

        $query = StudentEnrollment::query()->where('school_id', $schoolId)->where('academic_term_id', $termId)->where('status', 'active');
        $count = (clone $query)->count();
        $placements = (clone $query)->with('classArm')->select('academic_class_arm_id')->selectRaw('count(*) as aggregate')->groupBy('academic_class_arm_id')->orderBy('academic_class_arm_id')->get()->map(static fn (StudentEnrollment $row): array => ['class_arm_reference' => (string) $row->classArm?->public_id, 'student_count' => (int) $row->getAttribute('aggregate')])->values()->all();

        return ['active_enrollment_count' => $count, 'placement_count' => count($placements), 'denominator' => $count, 'placements' => $placements, 'issue_codes' => [], 'result_count' => count($placements), 'last_updated_at' => $query->latest('updated_at')->first()?->updated_at?->toISOString()];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function attendance(int $schoolId, ?int $termId, array $filters): array
    {
        if ($termId === null) {
            return ['submitted_register_count' => 0, 'locked_register_count' => 0, 'missing_register_count' => 0, 'entry_counts' => [], 'denominator' => 0, 'issue_codes' => ['ACADEMIC_CONTEXT_UNAVAILABLE'], 'result_count' => 0, 'last_updated_at' => null];
        }

        $registers = AcademicAttendanceRegister::query()->where('school_id', $schoolId)->where('academic_term_id', $termId)->whereIn('status', ['submitted', 'locked']);
        $this->dateFilter($registers, 'attendance_date', $filters);
        $submitted = (clone $registers)->where('status', 'submitted')->count();
        $locked = (clone $registers)->where('status', 'locked')->count();
        $activeArms = AcademicClassArm::query()->where('school_id', $schoolId)->where('status', 'active')->count();
        $coveredArms = (clone $registers)->distinct('academic_class_arm_id')->count('academic_class_arm_id');
        $entries = AcademicAttendanceEntry::query()->whereIn('attendance_register_id', (clone $registers)->select('id'))->select('status')->selectRaw('count(*) as aggregate')->groupBy('status')->orderBy('status')->pluck('aggregate', 'status')->map(static fn ($count): int => (int) $count)->all();
        $count = array_sum($entries);

        return ['submitted_register_count' => $submitted, 'locked_register_count' => $locked, 'missing_register_count' => max(0, $activeArms - $coveredArms), 'entry_counts' => $entries, 'denominator' => $count, 'issue_codes' => $activeArms > $coveredArms ? ['ATTENDANCE_REGISTER_MISSING'] : [], 'result_count' => $submitted + $locked, 'last_updated_at' => (clone $registers)->latest('updated_at')->first()?->updated_at?->toISOString()];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function academic(int $schoolId, ?int $termId, array $filters): array
    {
        if ($termId === null) {
            return ['run_counts' => [], 'computed_count' => 0, 'not_computable_count' => 0, 'denominator' => 0, 'issue_codes' => ['ACADEMIC_CONTEXT_UNAVAILABLE'], 'result_count' => 0, 'last_updated_at' => null];
        }

        $runs = AcademicResultRun::query()->where('school_id', $schoolId)->where('academic_term_id', $termId)->orderBy('status')->orderBy('id')->get();
        $runCounts = $runs->groupBy('status')->map->count()->sortKeys()->all();
        $notComputable = (int) $runs->sum('not_computable_count');
        $computed = (int) $runs->sum('computed_count');

        return ['run_counts' => $runCounts, 'computed_count' => $computed, 'not_computable_count' => $notComputable, 'denominator' => $computed + $notComputable, 'issue_codes' => $notComputable > 0 ? ['RESULT_NOT_COMPUTABLE'] : [], 'result_count' => $runs->count(), 'last_updated_at' => $runs->sortByDesc('updated_at')->first()?->updated_at?->toISOString()];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function finance(int $schoolId, ?int $termId, array $filters): array
    {
        $invoices = FeeInvoice::query()->where('school_id', $schoolId)->where('status', 'issued');
        if ($termId !== null) {
            $invoices->whereHas('assessment', static fn (Builder $query): Builder => $query->where('academic_term_id', $termId));
        }
        $this->dateFilter($invoices, 'issued_at', $filters);
        if (isset($filters['currency'])) {
            $invoices->where('currency', (string) $filters['currency']);
        }
        $invoiceCount = (clone $invoices)->count();
        $invoiceIds = (clone $invoices)->pluck('id');
        $allocations = FeePaymentAllocation::query()->where('school_id', $schoolId)->whereIn('fee_invoice_id', $invoiceIds)->where('status', 'allocated');
        $exceptions = FinanceReconciliationItem::query()->where('school_id', $schoolId)->where('status', 'exception');

        return ['issued_invoice_count' => $invoiceCount, 'allocated_payment_count' => (clone $allocations)->count(), 'reconciliation_exception_count' => $exceptions->count(), 'currencies' => (clone $invoices)->select('currency')->distinct()->orderBy('currency')->pluck('currency')->values()->all(), 'denominator' => $invoiceCount, 'issue_codes' => $exceptions->exists() ? ['RECONCILIATION_EXCEPTION'] : [], 'result_count' => $invoiceCount, 'last_updated_at' => (clone $invoices)->latest('updated_at')->first()?->updated_at?->toISOString()];
    }

    /** @param array<string, mixed> $filters */
    private function validatePeriod(array $filters): void
    {
        if (! isset($filters['from'], $filters['to'])) {
            return;
        }
        $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['from']);
        $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['to']);
        if ($from === null || $to === null || $from->greaterThan($to) || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => ['The reporting period must be within 366 days.']]);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     */
    private function dateFilter(Builder $query, string $column, array $filters): void
    {
        if (isset($filters['from'])) {
            $query->whereDate($column, '>=', (string) $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate($column, '<=', (string) $filters['to']);
        }
    }
}
