<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Finance\Application\Services\FinancePeriodGuard;
use App\Contexts\Finance\Domain\Models\FeeAssessmentItem;
use App\Contexts\Finance\Domain\Models\FeeAssessmentRun;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeeInvoiceLine;
use App\Contexts\Finance\Domain\Models\FeePlanVersion;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FeeAssessmentAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @param array{plan_id: string, due_date?: string|null} $data
     * @return array{assessment: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function generate(UserIdentity $actor, string $school, string $sessionId, string $termId, array $data): array
    {
        $this->authorize($actor, $school, 'finance.fee-assessments.manage');
        $context = TenantContext::require();
        [$session, $term, $plan] = $this->parents($context->schoolId, $sessionId, $termId, $data['plan_id']);
        FinancePeriodGuard::assertOpenForTerm($context->schoolId, (int) $term->getKey());
        $dueDate = $data['due_date'] ?? CarbonImmutable::parse((string) $plan->effective_to)->toDateString();
        $this->assertDueDate($term, $dueDate);

        return DB::transaction(function () use ($actor, $context, $session, $term, $plan, $dueDate): array {
            $existing = FeeAssessmentRun::query()->where('school_id', $context->schoolId)->where('academic_term_id', $term->getKey())->where('fee_plan_version_id', $plan->getKey())->lockForUpdate()->first();
            if ($existing instanceof FeeAssessmentRun) {
                return ['assessment' => $this->runData($existing->load(['session', 'term', 'plan']))];
            }

            $run = FeeAssessmentRun::query()->create(['school_id' => $context->schoolId, 'academic_session_id' => $session->getKey(), 'academic_term_id' => $term->getKey(), 'fee_plan_version_id' => $plan->getKey(), 'due_date' => $dueDate, 'status' => 'draft', 'generated_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $enrollments = $this->eligibleEnrollments($plan, $context->schoolId, $term->getKey(), $session->getKey());
            foreach ($enrollments as $enrollment) {
                $invoice = FeeInvoice::query()->create(['school_id' => $context->schoolId, 'fee_assessment_run_id' => $run->getKey(), 'student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->getKey(), 'currency' => $plan->currency, 'total_minor_units' => $plan->lines->sum('amount_minor_units'), 'due_date' => $dueDate, 'status' => 'draft']);
                foreach ($plan->lines as $line) {
                    FeeInvoiceLine::query()->create(['school_id' => $context->schoolId, 'fee_invoice_id' => $invoice->getKey(), 'fee_plan_line_id' => $line->getKey(), 'fee_category_id' => $line->fee_category_id, 'category_code' => $line->category->code, 'category_name' => $line->category->name, 'amount_minor_units' => $line->amount_minor_units, 'sequence' => $line->sequence, 'is_required' => $line->is_required]);
                }
                FeeAssessmentItem::query()->create(['school_id' => $context->schoolId, 'fee_assessment_run_id' => $run->getKey(), 'fee_invoice_id' => $invoice->getKey(), 'student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->getKey(), 'status' => 'draft', 'total_minor_units' => $invoice->total_minor_units]);
            }
            $this->record($actor, 'finance.fee_assessment_generated', 'fee_assessment_run', (string) $run->public_id, ['to' => 'draft', 'item_count' => $enrollments->count()]);

            return ['assessment' => $this->runData($run->fresh()->load(['session', 'term', 'plan']))];
        });
    }

    /** @return array{assessments: list<array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>>} */
    public function list(UserIdentity $actor, string $school, string $sessionId, string $termId): array
    {
        $this->authorize($actor, $school, 'finance.fee-assessments.read');
        $term = $this->term($sessionId, $termId);
        $runs = FeeAssessmentRun::query()->where('academic_term_id', $term->getKey())->with(['session', 'term', 'plan'])->latest('id')->get();

        return ['assessments' => $runs->map(fn (FeeAssessmentRun $run): array => $this->runData($run))->all()];
    }

    /** @return array{assessment: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function show(UserIdentity $actor, string $school, string $sessionId, string $termId, string $assessmentId): array
    {
        $this->authorize($actor, $school, 'finance.fee-assessments.read');
        $term = $this->term($sessionId, $termId);
        $run = FeeAssessmentRun::query()->where('academic_term_id', $term->getKey())->where('public_id', $assessmentId)->with(['session', 'term', 'plan'])->firstOrFail();

        return ['assessment' => $this->runData($run)];
    }

    /** @return array{assessment: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function finalize(UserIdentity $actor, string $school, string $assessmentId): array
    {
        $this->authorize($actor, $school, 'finance.fee-assessments.manage');

        return DB::transaction(function () use ($actor, $assessmentId): array {
            $run = FeeAssessmentRun::query()->where('public_id', $assessmentId)->with(['session', 'term', 'plan'])->lockForUpdate()->firstOrFail();
            if ($run->status === 'finalized') {
                return ['assessment' => $this->runData($run)];
            }
            FinancePeriodGuard::assertOpenForTerm((int) $run->school_id, (int) $run->academic_term_id);
            if ($run->status !== 'draft') {
                throw ValidationException::withMessages(['assessment' => ['Only draft assessments can be finalized.']]);
            }
            $run->update(['status' => 'finalized', 'finalized_by' => $actor->getKey(), 'finalized_at' => now()]);
            $run->invoices()->update(['status' => 'issued', 'issued_by' => $actor->getKey(), 'issued_at' => now()]);
            $run->items()->update(['status' => 'finalized']);
            $this->record($actor, 'finance.fee_assessment_finalized', 'fee_assessment_run', (string) $run->public_id, ['from' => 'draft', 'to' => 'finalized']);

            return ['assessment' => $this->runData($run->fresh()->load(['session', 'term', 'plan']))];
        });
    }

    /** @return array{invoices: list<array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>>, meta?: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function invoices(UserIdentity $actor, string $school, int $page, int $perPage): array
    {
        $this->authorize($actor, $school, 'finance.invoices.read');
        $paginator = FeeInvoice::query()->with(['student', 'assessment.plan'])->latest('id')->paginate($perPage, ['*'], 'page', $page);

        return ['invoices' => $paginator->getCollection()->map(fn (FeeInvoice $invoice): array => $this->invoiceData($invoice))->all(), 'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]];
    }

    /** @return array{invoice: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function invoice(UserIdentity $actor, string $school, string $invoiceId): array
    {
        $this->authorize($actor, $school, 'finance.invoices.read');

        return ['invoice' => $this->invoiceData(FeeInvoice::query()->where('public_id', $invoiceId)->with(['student', 'assessment.plan', 'lines'])->firstOrFail())];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @return array{0: AcademicSession, 1: AcademicTerm, 2: FeePlanVersion} */
    private function parents(int $schoolId, string $sessionId, string $termId, string $planId): array
    {
        $session = AcademicSession::query()->where('school_id', $schoolId)->where('public_id', $sessionId)->first();
        $term = AcademicTerm::query()->where('school_id', $schoolId)->where('academic_session_id', $session?->getKey())->where('public_id', $termId)->first();
        $plan = FeePlanVersion::query()->where('school_id', $schoolId)->where('academic_session_id', $session?->getKey())->where('academic_term_id', $term?->getKey())->where('public_id', $planId)->with(['lines.category', 'level', 'section', 'classArm'])->first();
        if (! $session instanceof AcademicSession || ! $term instanceof AcademicTerm || ! $plan instanceof FeePlanVersion) {
            throw new ModelNotFoundException;
        }
        if ($session->status === 'closed' || $term->status === 'closed' || $plan->status !== 'active') {
            throw new ModelNotFoundException;
        }

        return [$session, $term, $plan];
    }

    private function term(string $sessionId, string $termId): AcademicTerm
    {
        $context = TenantContext::require();
        $term = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $termId)->whereHas('session', fn ($query) => $query->where('public_id', $sessionId))->first();

        return $term instanceof AcademicTerm ? $term : throw new ModelNotFoundException;
    }

    private function assertDueDate(AcademicTerm $term, string $date): void
    {
        $dueDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if ($dueDate === null || $dueDate->lt(CarbonImmutable::parse((string) $term->start_date)) || $dueDate->gt(CarbonImmutable::parse((string) $term->end_date))) {
            throw ValidationException::withMessages(['due_date' => ['The due date must be contained within the academic term.']]);
        }
    }

    /** @return Collection<int, StudentEnrollment> */
    private function eligibleEnrollments(FeePlanVersion $plan, int $schoolId, int $termId, int $sessionId): Collection
    {
        $query = StudentEnrollment::query()->where('school_id', $schoolId)->where('academic_session_id', $sessionId)->where('academic_term_id', $termId)->where('status', 'active')->whereHas('student', fn ($student) => $student->where('status', 'active'))->with('student');
        foreach ([['academic_level_id', $plan->academic_level_id], ['academic_section_id', $plan->academic_section_id], ['academic_class_arm_id', $plan->academic_class_arm_id]] as [$column, $value]) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        return $query->lockForUpdate()->get();
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function runData(FeeAssessmentRun $run): array
    {
        return ['id' => (string) $run->public_id, 'session_id' => (string) $run->session?->public_id, 'term_id' => (string) $run->term?->public_id, 'plan_id' => (string) $run->plan?->public_id, 'plan_version' => (int) $run->plan?->version, 'due_date' => CarbonImmutable::parse((string) $run->due_date)->toDateString(), 'status' => (string) $run->status, 'item_count' => $run->items()->count(), 'invoice_count' => $run->invoices()->count(), 'created_at' => CarbonImmutable::parse((string) $run->created_at)->toISOString(), 'finalized_at' => $run->finalized_at === null ? null : CarbonImmutable::parse((string) $run->finalized_at)->toISOString()];
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function invoiceData(FeeInvoice $invoice): array
    {
        return ['id' => (string) $invoice->public_id, 'invoice_number' => (string) $invoice->invoice_number, 'student_id' => (string) $invoice->student?->public_id, 'display_name' => (string) $invoice->student?->display_name, 'assessment_id' => (string) $invoice->assessment?->public_id, 'plan_id' => (string) $invoice->assessment?->plan?->public_id, 'currency' => (string) $invoice->currency, 'total_minor_units' => (int) $invoice->total_minor_units, 'due_date' => CarbonImmutable::parse((string) $invoice->due_date)->toDateString(), 'status' => (string) $invoice->status, 'lines' => $invoice->relationLoaded('lines') ? $invoice->lines->map(fn (FeeInvoiceLine $line): array => ['id' => (string) $line->public_id, 'category_code' => $line->category_code, 'category_name' => $line->category_name, 'amount_minor_units' => (int) $line->amount_minor_units, 'sequence' => (int) $line->sequence])->all() : []];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $transition */
    private function record(UserIdentity $actor, string $action, string $subjectType, string $subjectId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: $subjectType, subjectPublicId: $subjectId, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
