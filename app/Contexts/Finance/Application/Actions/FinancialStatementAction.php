<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FinancialLedgerBatch;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;

final class FinancialStatementAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array<string, mixed> */
    public function forSchool(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school);
        $context = TenantContext::require();
        $paginator = $this->invoiceQuery($context->schoolId)->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $items = $paginator->getCollection()->map(fn (FeeInvoice $invoice): array => $this->invoiceData($invoice))->all();
        $this->auditRead($actor, $context->schoolId, count($items));

        return ['items' => $items, 'meta' => $this->meta($paginator)];
    }

    /** @return array<string, mixed> */
    public function forStudent(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorizeSubject($actor, $school, $student, null);
        $context = TenantContext::require();
        $studentModel = Student::query()->where('school_id', $context->schoolId)->where('public_id', $student)->firstOrFail();
        $invoices = $this->invoiceQuery($context->schoolId)->where('student_id', $studentModel->getKey())->get();
        $result = $this->statement($studentModel, $invoices);
        $this->auditRead($actor, $context->schoolId, count($invoices));

        return ['statement' => $result];
    }

    /** @return array<string, mixed> */
    public function forGuardian(UserIdentity $actor, string $school, string $guardian): array
    {
        $this->authorizeSubject($actor, $school, null, $guardian);
        $context = TenantContext::require();
        $relationships = StudentGuardianRelationship::query()->where('school_id', $context->schoolId)->where('status', 'active')->whereNotNull('verified_at')->whereHas('guardianProfile', fn ($query) => $query->whereHas('identity', fn ($identity) => $identity->where('public_id', $guardian)))->with('student')->get();
        if ($relationships->isEmpty()) {
            throw new ModelNotFoundException;
        }
        $studentIds = $relationships->pluck('student_id')->unique();
        $invoices = $this->invoiceQuery($context->schoolId)->whereIn('student_id', $studentIds)->get()->groupBy('student_id');
        $students = $relationships->map(fn (StudentGuardianRelationship $relationship): array => $this->statement($relationship->student, $invoices->get($relationship->student_id, collect())))->values()->all();
        $this->auditRead($actor, $context->schoolId, count($students));

        return ['guardian_id' => $guardian, 'students' => $students];
    }

    /** @return Builder<FeeInvoice> */
    private function invoiceQuery(int $schoolId): Builder
    {
        return FeeInvoice::query()->where('school_id', $schoolId)->where('status', 'issued')->with(['student', 'lines', 'adjustments' => fn ($query) => $query->where('status', 'applied'), 'enrollment'])->orderBy('student_id')->orderByDesc('due_date')->orderBy('id');
    }

    /** @param Collection<int, FeeInvoice> $invoices
     * @return array<string, mixed> */
    private function statement(?Student $student, Collection $invoices): array
    {
        $rows = $invoices->map(fn (FeeInvoice $invoice): array => $this->invoiceData($invoice))->values()->all();
        $totals = [];
        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $totals[$currency] ??= ['invoiced_minor_units' => 0, 'adjusted_minor_units' => 0, 'allocated_minor_units' => 0, 'outstanding_minor_units' => 0];
            foreach (array_keys($totals[$currency]) as $key) {
                $totals[$currency][$key] += (int) $row[$key];
            }
        }

        return ['student' => ['id' => (string) $student?->public_id, 'student_number' => (string) $student?->student_number, 'display_name' => (string) $student?->display_name], 'invoices' => $rows, 'totals' => $totals];
    }

    /** @return array<string, mixed> */
    private function invoiceData(FeeInvoice $invoice): array
    {
        $adjusted = (int) $invoice->adjustments->sum('amount_minor_units');
        $allocated = (int) FeePaymentAllocation::query()->where('school_id', $invoice->school_id)->where('fee_invoice_id', $invoice->getKey())->where('status', 'allocated')->sum('amount_minor_units');
        $outstanding = max(0, (int) $invoice->total_minor_units - $adjusted - $allocated);
        $ledgerAmount = (int) FinancialLedgerBatch::query()->where('school_id', $invoice->school_id)->where('source_type', 'invoice')->where('source_public_id', $invoice->public_id)->where('status', 'posted')->with('entries')->get()->sum(fn (FinancialLedgerBatch $batch): int => (int) $batch->entries->where('direction', 'debit')->sum('amount_minor_units'));
        $issues = [];
        if ($ledgerAmount === 0) {
            $issues[] = 'LEDGER_NOT_POSTED';
        } elseif ($ledgerAmount !== (int) $invoice->total_minor_units) {
            $issues[] = 'LEDGER_MISMATCH';
        }

        return ['id' => (string) $invoice->public_id, 'invoice_number' => (string) $invoice->invoice_number, 'currency' => (string) $invoice->currency, 'invoiced_minor_units' => (int) $invoice->total_minor_units, 'adjusted_minor_units' => $adjusted, 'allocated_minor_units' => $allocated, 'outstanding_minor_units' => $outstanding, 'due_date' => $this->date($invoice->due_date), 'status' => (string) $invoice->status, 'aging_bucket' => $this->aging((string) $invoice->due_date), 'issues' => $issues, 'lines' => $invoice->lines->map(fn ($line): array => ['category_code' => (string) $line->category_code, 'category_name' => (string) $line->category_name, 'amount_minor_units' => (int) $line->amount_minor_units, 'sequence' => (int) $line->sequence])->values()->all()];
    }

    private function aging(string $dueDate): string
    {
        $days = now()->startOfDay()->diffInDays($dueDate, false);

        return $days >= 0 ? 'current' : (abs($days) <= 30 ? '1_30' : (abs($days) <= 60 ? '31_60' : (abs($days) <= 90 ? '61_90' : 'over_90')));
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : ($value === null ? null : (string) $value);
    }

    /**
     * @param  LengthAwarePaginator<int, FeeInvoice>  $paginator
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function meta(LengthAwarePaginator $paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()];
    }

    private function authorize(UserIdentity $actor, string $school): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'finance.statements.read')) {
            throw new ModelNotFoundException;
        }
    }

    private function authorizeSubject(UserIdentity $actor, string $school, ?string $student, ?string $guardian): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school) {
            throw new ModelNotFoundException;
        }
        if ($this->permissions->allows($actor, $school, 'finance.statements.read')) {
            return;
        }
        if ($guardian !== null && $guardian === (string) $actor->public_id) {
            return;
        }
        if ($student !== null && StudentGuardianRelationship::query()->where('school_id', $context->schoolId)->where('status', 'active')->whereNotNull('verified_at')->where('student_id', Student::query()->where('public_id', $student)->value('id'))->whereHas('guardianProfile', fn ($query) => $query->where('user_id', $actor->getKey()))->exists()) {
            return;
        }
        throw new ModelNotFoundException;
    }

    private function auditRead(UserIdentity $actor, int $schoolId, int $count): void
    {
        $this->audit->execute(new AuditEventData(action: 'finance.statement_read', subjectType: 'financial_statement_projection', subjectPublicId: null, schoolId: $schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), metadata: ['result_count' => $count], requiresSchoolContext: true));
    }
}
