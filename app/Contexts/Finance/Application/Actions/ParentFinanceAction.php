<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Http\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class ParentFinanceAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function balances(UserIdentity $actor): LengthAwarePaginator
    {
        $paginator = $this->relationships($actor)->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => $this->balance($row))->values());
        $this->auditRead($actor, (int) $paginator->total(), 'parent_fee_balances_read');

        return $paginator;
    }

    /** @return array{child: array<string, mixed>} */
    public function balanceForChild(UserIdentity $actor, string $student): array
    {
        $row = $this->relationships($actor)->where('students.public_id', $student)->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        $this->auditRead($actor, 1, 'parent_fee_balance_read');

        return ['child' => $this->balance($row)];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function receipts(UserIdentity $actor): LengthAwarePaginator
    {
        $paginator = $this->relationships($actor)->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => $this->receiptsFor($row))->values());
        $this->auditRead($actor, (int) $paginator->total(), 'parent_receipts_read');

        return $paginator;
    }

    /** @return array{child: array<string, mixed>} */
    public function receiptsForChild(UserIdentity $actor, string $student): array
    {
        $row = $this->relationships($actor)->where('students.public_id', $student)->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        $this->auditRead($actor, 1, 'parent_receipts_child_read');

        return ['child' => $this->receiptsFor($row)];
    }

    private function relationships(UserIdentity $actor): Builder
    {
        return DB::table('student_guardian_relationships')
            ->join('guardian_profiles', 'guardian_profiles.id', '=', 'student_guardian_relationships.guardian_profile_id')
            ->join('students', 'students.id', '=', 'student_guardian_relationships.student_id')
            ->join('schools', 'schools.id', '=', 'student_guardian_relationships.school_id')
            ->where('guardian_profiles.user_id', $actor->getKey())
            ->where('student_guardian_relationships.status', 'active')
            ->whereNotNull('student_guardian_relationships.verified_at')
            ->whereNull('student_guardian_relationships.revoked_at')
            ->where('students.status', 'active')
            ->where('schools.status', 'active')
            ->select([
                'students.id as student_id',
                'students.public_id as student_public_id',
                'students.student_number',
                'students.display_name',
                'student_guardian_relationships.school_id',
                'schools.public_id as school_public_id',
                'schools.name as school_name',
            ])
            ->orderBy('schools.name')
            ->orderBy('students.display_name')
            ->orderBy('students.id');
    }

    /** @return array<string, mixed> */
    private function balance(object $row): array
    {
        $invoices = $this->invoices((int) $row->school_id, (int) $row->student_id);
        $totals = [];
        foreach ($invoices as $invoice) {
            $currency = (string) $invoice['currency'];
            $totals[$currency] ??= ['invoiced_minor_units' => 0, 'adjusted_minor_units' => 0, 'allocated_minor_units' => 0, 'outstanding_minor_units' => 0];
            foreach (array_keys($totals[$currency]) as $key) {
                $totals[$currency][$key] += (int) $invoice[$key];
            }
        }

        return $this->child($row) + ['invoices' => $invoices, 'totals' => $totals];
    }

    /** @return array<string, mixed> */
    private function receiptsFor(object $row): array
    {
        $invoiceIds = DB::table('fee_invoices')
            ->where('school_id', $row->school_id)
            ->where('student_id', $row->student_id)
            ->where('status', 'issued')
            ->pluck('id');
        $receipts = DB::table('finance_receipts')
            ->join('fee_invoices', 'fee_invoices.id', '=', 'finance_receipts.fee_invoice_id')
            ->where('finance_receipts.school_id', $row->school_id)
            ->where('finance_receipts.status', 'issued')
            ->whereIn('finance_receipts.fee_invoice_id', $invoiceIds)
            ->select([
                'finance_receipts.public_id',
                'finance_receipts.receipt_number',
                'finance_receipts.amount_minor_units',
                'finance_receipts.currency',
                'finance_receipts.issue_date',
                'finance_receipts.status',
                'fee_invoices.public_id as invoice_public_id',
                'fee_invoices.invoice_number',
            ])
            ->orderByDesc('issue_date')
            ->orderByDesc('finance_receipts.id')
            ->get()
            ->map(fn (object $receipt): array => [
                'id' => (string) $receipt->public_id,
                'receipt_number' => (string) $receipt->receipt_number,
                'invoice_id' => (string) $receipt->invoice_public_id,
                'invoice_number' => (string) $receipt->invoice_number,
                'amount_minor_units' => (int) $receipt->amount_minor_units,
                'currency' => (string) $receipt->currency,
                'issue_date' => $receipt->issue_date === null ? null : (string) $receipt->issue_date,
                'status' => (string) $receipt->status,
            ])->values()->all();

        return $this->child($row) + ['receipts' => $receipts];
    }

    /** @return list<array<string, mixed>> */
    private function invoices(int $schoolId, int $studentId): array
    {
        return FeeInvoice::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->where('status', 'issued')
            ->with(['lines', 'adjustments' => fn ($query) => $query->where('status', 'applied')])
            ->orderByDesc('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (FeeInvoice $invoice): array {
                $adjusted = (int) $invoice->adjustments->sum('amount_minor_units');
                $allocated = (int) FeePaymentAllocation::withoutGlobalScopes()->where('school_id', $invoice->school_id)->where('fee_invoice_id', $invoice->getKey())->where('status', 'allocated')->sum('amount_minor_units');

                return [
                    'id' => (string) $invoice->public_id,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'currency' => (string) $invoice->currency,
                    'invoiced_minor_units' => (int) $invoice->total_minor_units,
                    'adjusted_minor_units' => $adjusted,
                    'allocated_minor_units' => $allocated,
                    'outstanding_minor_units' => max(0, (int) $invoice->total_minor_units - $adjusted - $allocated),
                    'due_date' => $this->date($invoice->due_date),
                    'status' => (string) $invoice->status,
                    'aging_bucket' => $this->aging((string) $invoice->due_date),
                    'lines' => $invoice->lines->map(fn ($line): array => [
                        'category_code' => (string) $line->category_code,
                        'category_name' => (string) $line->category_name,
                        'amount_minor_units' => (int) $line->amount_minor_units,
                        'sequence' => (int) $line->sequence,
                    ])->values()->all(),
                ];
            })->values()->all();
    }

    /** @return array<string, mixed> */
    private function child(object $row): array
    {
        return [
            'student' => ['id' => (string) $row->student_public_id, 'student_number' => (string) $row->student_number, 'display_name' => (string) $row->display_name],
            'school' => ['id' => (string) $row->school_public_id, 'name' => (string) $row->school_name],
        ];
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

    private function auditRead(UserIdentity $actor, int $count, string $action): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'parent_finance_projection', actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), metadata: ['result_count' => $count, 'caller_type' => 'verified_guardian']));
    }
}
