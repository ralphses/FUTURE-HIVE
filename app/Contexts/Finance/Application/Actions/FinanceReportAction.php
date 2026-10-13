<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePlanVersion;
use App\Contexts\Finance\Domain\Models\FinanceReconciliationItem;
use App\Contexts\Finance\Domain\Models\FinancialLedgerBatch;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

final class FinanceReportAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function report(UserIdentity $actor, string $school, string $kind, array $filters = []): array
    {
        $this->authorize($actor, $school, 'finance.reports.read');
        $context = TenantContext::require();
        $invoices = $this->invoiceQuery($context->schoolId, $filters)->limit(5000)->get();
        $rows = match ($kind) {
            'collections' => $this->collections($invoices),
            'arrears' => $this->arrears($invoices),
            'fee-plans' => $this->feePlans($invoices),
            default => throw new ModelNotFoundException,
        };
        $summary = $this->summary($invoices, $context->schoolId);
        $this->auditRead($actor, $context->schoolId, $kind, count($rows));

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function export(UserIdentity $actor, string $school, string $kind, array $filters = []): array
    {
        $this->authorize($actor, $school, 'finance.reports.export');

        return $this->report($actor, $school, $kind, $filters);
    }

    /** @param array<string, mixed> $filters
     * @return Builder<FeeInvoice>
     */
    private function invoiceQuery(int $schoolId, array $filters): Builder
    {
        $this->validatePeriod($filters);
        $query = FeeInvoice::query()->where('school_id', $schoolId)->where('status', 'issued')->with(['lines', 'adjustments' => fn ($query) => $query->where('status', 'applied'), 'assessment.plan']);
        if (isset($filters['from'])) {
            $query->whereDate('issued_at', '>=', (string) $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('issued_at', '<=', (string) $filters['to']);
        }
        if (isset($filters['term_id'])) {
            $query->whereHas('assessment.term', fn ($term) => $term->where('public_id', (string) $filters['term_id']));
        }
        if (isset($filters['fee_plan_id'])) {
            $query->whereHas('assessment.plan', fn ($plan) => $plan->where('public_id', (string) $filters['fee_plan_id']));
        }
        if (isset($filters['category_code'])) {
            $query->whereHas('lines', fn ($line) => $line->where('category_code', (string) $filters['category_code']));
        }
        if (isset($filters['currency'])) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }

        return $query->orderBy('currency')->orderBy('due_date')->orderBy('id');
    }

    /** @param array<string, mixed> $filters */
    private function validatePeriod(array $filters): void
    {
        if (! isset($filters['from'], $filters['to'])) {
            return;
        }

        $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['from']);
        $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['to']);
        if ($from === null || $to === null || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => ['The reporting period must not exceed 366 days.']]);
        }
    }

    /** @param Collection<int, FeeInvoice> $invoices
     * @return list<array<string, mixed>>
     */
    private function collections(Collection $invoices): array
    {
        $totals = [];
        foreach ($invoices as $invoice) {
            $row = $this->invoiceRow($invoice);
            $currency = (string) $row['currency'];
            $totals[$currency] ??= ['currency' => $currency, 'invoiced_minor_units' => 0, 'adjusted_minor_units' => 0, 'allocated_minor_units' => 0, 'outstanding_minor_units' => 0, 'invoice_count' => 0];
            foreach (['invoiced_minor_units', 'adjusted_minor_units', 'allocated_minor_units', 'outstanding_minor_units'] as $key) {
                $totals[$currency][$key] += (int) $row[$key];
            }
            $totals[$currency]['invoice_count']++;
        }

        return array_values($totals);
    }

    /** @param Collection<int, FeeInvoice> $invoices
     * @return list<array<string, mixed>>
     */
    private function arrears(Collection $invoices): array
    {
        $rows = [];
        foreach ($invoices as $invoice) {
            $row = $this->invoiceRow($invoice);
            if ((int) $row['outstanding_minor_units'] < 1) {
                continue;
            }
            $rows[] = ['invoice_id' => $row['invoice_id'], 'invoice_number' => $row['invoice_number'], 'currency' => $row['currency'], 'outstanding_minor_units' => $row['outstanding_minor_units'], 'due_date' => $row['due_date'], 'aging_bucket' => $row['aging_bucket'], 'issues' => $row['issues']];
        }

        return $rows;
    }

    /** @param Collection<int, FeeInvoice> $invoices
     * @return list<array<string, mixed>>
     */
    private function feePlans(Collection $invoices): array
    {
        $rows = [];
        foreach ($invoices as $invoice) {
            $plan = $invoice->assessment?->plan;
            $planId = $plan instanceof FeePlanVersion ? (string) $plan->public_id : '';
            $planName = $plan instanceof FeePlanVersion ? (string) $plan->name : 'Unresolved plan';
            foreach ($invoice->lines as $line) {
                $key = implode('|', [$planId ?: 'unknown', (string) $line->category_code, (string) $invoice->currency]);
                $rows[$key] ??= ['fee_plan_id' => $planId, 'fee_plan_name' => $planName, 'category_code' => (string) $line->category_code, 'category_name' => (string) $line->category_name, 'currency' => (string) $invoice->currency, 'invoiced_minor_units' => 0, 'invoice_count' => 0];
                $rows[$key]['invoiced_minor_units'] += (int) $line->amount_minor_units;
                $rows[$key]['invoice_count']++;
            }
        }

        return array_values($rows);
    }

    /** @return array<string, mixed> */
    private function invoiceRow(FeeInvoice $invoice): array
    {
        $adjusted = (int) $invoice->adjustments->sum('amount_minor_units');
        $allocated = (int) FeePaymentAllocation::query()->where('school_id', $invoice->school_id)->where('fee_invoice_id', $invoice->getKey())->where('status', 'allocated')->sum('amount_minor_units');
        $outstanding = max(0, (int) $invoice->total_minor_units - $adjusted - $allocated);
        $ledgerAmount = (int) FinancialLedgerBatch::query()->where('school_id', $invoice->school_id)->where('source_type', 'invoice')->where('source_public_id', $invoice->public_id)->where('status', 'posted')->with('entries')->get()->sum(fn (FinancialLedgerBatch $batch): int => (int) $batch->entries->where('direction', 'debit')->sum('amount_minor_units'));
        $issues = $ledgerAmount === 0 ? ['LEDGER_NOT_POSTED'] : ($ledgerAmount !== (int) $invoice->total_minor_units ? ['LEDGER_MISMATCH'] : []);
        if (FinanceReconciliationItem::query()->where('school_id', $invoice->school_id)->where('status', 'exception')->where('source_public_id', $invoice->public_id)->exists()) {
            $issues[] = 'RECONCILIATION_EXCEPTION';
        }

        return ['invoice_id' => (string) $invoice->public_id, 'invoice_number' => (string) $invoice->invoice_number, 'currency' => (string) $invoice->currency, 'invoiced_minor_units' => (int) $invoice->total_minor_units, 'adjusted_minor_units' => $adjusted, 'allocated_minor_units' => $allocated, 'outstanding_minor_units' => $outstanding, 'due_date' => $this->date($invoice->due_date), 'aging_bucket' => $this->aging((string) $invoice->due_date), 'issues' => array_values(array_unique($issues))];
    }

    /** @param Collection<int, FeeInvoice> $invoices
     * @return array<string, mixed>
     */
    private function summary(Collection $invoices, int $schoolId): array
    {
        $rows = $invoices->map(fn (FeeInvoice $invoice): array => $this->invoiceRow($invoice));
        $totals = [];
        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $totals[$currency] ??= ['invoiced_minor_units' => 0, 'adjusted_minor_units' => 0, 'allocated_minor_units' => 0, 'outstanding_minor_units' => 0];
            foreach (array_keys($totals[$currency]) as $key) {
                $totals[$currency][$key] += (int) $row[$key];
            }
        }

        return ['currencies' => $totals, 'reconciliation_exception_count' => FinanceReconciliationItem::query()->where('school_id', $schoolId)->where('status', 'exception')->count(), 'invoice_count' => $invoices->count()];
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

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function auditRead(UserIdentity $actor, int $schoolId, string $kind, int $count): void
    {
        $this->audit->execute(new AuditEventData(action: 'finance.report_read', subjectType: 'finance_report_projection', subjectPublicId: null, schoolId: $schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), metadata: ['report' => $kind, 'result_count' => $count], requiresSchoolContext: true));
    }
}
