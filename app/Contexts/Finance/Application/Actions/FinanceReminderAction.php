<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Application\Contracts\ReminderDelivery;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FinanceReminder;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceReminderAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly ReminderDelivery $delivery,
    ) {}

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    public function candidates(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.reminders.read');
        $context = TenantContext::require();
        $paginator = $this->invoiceQuery($context->schoolId)->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $items = $paginator->getCollection()->flatMap(fn (FeeInvoice $invoice): array => $this->candidateData($invoice))->values()->all();
        $this->auditRead($actor, $context->schoolId, count($items));

        return ['candidates' => $items, 'meta' => $this->meta($paginator)];
    }

    /** @param array{invoice_id: string, recipient_id: string, reminder_type: string, period_key: string} $input
     * @return array{reminder: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'finance.reminders.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $input): array {
            $invoice = $this->invoice((string) $input['invoice_id'], true);
            $balance = $this->eligibleInvoice($invoice, $context->schoolId);
            $relationship = $this->relationship($context->schoolId, (int) $invoice->student_id, (string) $input['recipient_id']);
            $periodKey = trim((string) $input['period_key']);
            if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $periodKey) !== 1) {
                throw ValidationException::withMessages(['period_key' => ['The reminder period is invalid.']]);
            }
            $reminderType = trim((string) $input['reminder_type']);
            $hash = $this->recipientHash($context->schoolId, (string) $input['recipient_id']);
            $existing = FinanceReminder::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->where('recipient_reference_hash', $hash)->where('reminder_type', $reminderType)->where('period_key', $periodKey)->lockForUpdate()->first();
            if ($existing instanceof FinanceReminder) {
                return ['reminder' => $this->data($existing->load('invoice'))];
            }
            $reminder = FinanceReminder::query()->create([
                'school_id' => $context->schoolId,
                'fee_invoice_id' => $invoice->getKey(),
                'student_id' => $invoice->student_id,
                'student_enrollment_id' => $invoice->student_enrollment_id,
                'guardian_profile_id' => $relationship->guardian_profile_id,
                'recipient_public_id' => $input['recipient_id'],
                'recipient_reference_hash' => $hash,
                'reminder_type' => $reminderType,
                'period_key' => $periodKey,
                'amount_minor_units' => $balance,
                'currency' => $invoice->currency,
                'due_date' => $invoice->due_date,
                'status' => 'draft',
                'created_by' => $actor->getKey(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->record($actor, 'finance.reminder_created', $reminder, ['to' => 'draft']);

            return ['reminder' => $this->data($reminder->load('invoice'))];
        });
    }

    /** @return array{reminder: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function show(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.reminders.read');
        $reminder = $this->reminder($id);
        $this->auditRead($actor, (int) $reminder->school_id, 1);

        return ['reminder' => $this->data($reminder->load('invoice'))];
    }

    /** @return array{reminder: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function send(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.reminders.send');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $id): array {
            $reminder = $this->reminder($id, true);
            if ((string) $reminder->status === 'sent') {
                return ['reminder' => $this->data($reminder->load('invoice'))];
            }
            if (! in_array((string) $reminder->status, ['draft', 'failed'], true)) {
                throw new ModelNotFoundException;
            }
            $invoice = $this->invoice((string) $reminder->invoice?->public_id, true);
            $balance = $this->eligibleInvoice($invoice, $context->schoolId);
            $this->relationship($context->schoolId, (int) $invoice->student_id, (string) $reminder->recipient_public_id);
            $reminder->update(['status' => 'queued', 'amount_minor_units' => $balance, 'queued_at' => now(), 'failure_code' => null]);
            $result = $this->delivery->send((string) $reminder->recipient_public_id, (string) $reminder->public_id, $balance, (string) $invoice->currency);
            if ($result['accepted']) {
                $reminder->update(['status' => 'sent', 'sent_at' => now()]);
                $this->record($actor, 'finance.reminder_sent', $reminder, ['from' => 'queued', 'to' => 'sent']);
            } else {
                $reminder->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => $result['failure_code']]);
                $this->record($actor, 'finance.reminder_failed', $reminder, ['from' => 'queued', 'to' => 'failed']);
            }

            return ['reminder' => $this->data($reminder->fresh()->load('invoice'))];
        });
    }

    /** @return array{reminder: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function cancel(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.reminders.manage');

        return DB::transaction(function () use ($actor, $id): array {
            $reminder = $this->reminder($id, true);
            if ((string) $reminder->status === 'cancelled') {
                return ['reminder' => $this->data($reminder->load('invoice'))];
            }
            if (! in_array((string) $reminder->status, ['draft', 'queued', 'failed'], true)) {
                throw new ModelNotFoundException;
            }
            $reminder->update(['status' => 'cancelled', 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()]);
            $this->record($actor, 'finance.reminder_cancelled', $reminder, ['to' => 'cancelled']);

            return ['reminder' => $this->data($reminder->fresh()->load('invoice'))];
        });
    }

    /** @return Builder<FeeInvoice> */
    private function invoiceQuery(int $schoolId): Builder
    {
        return FeeInvoice::query()->where('school_id', $schoolId)->where('status', 'issued')->whereHas('student', fn ($query) => $query->where('status', 'active'))->whereHas('enrollment', fn ($query) => $query->where('status', 'active'))->with(['student', 'enrollment'])->orderBy('due_date')->orderBy('id');
    }

    /** @return list<array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>> */
    private function candidateData(FeeInvoice $invoice): array
    {
        $balance = $this->outstanding($invoice);
        if ($balance <= 0) {
            return [];
        }

        return StudentGuardianRelationship::query()->where('school_id', $invoice->school_id)->where('student_id', $invoice->student_id)->where('status', 'active')->whereNotNull('verified_at')->whereHas('guardianProfile.identity.contacts', fn ($query) => $query->whereNotNull('verified_at')->whereNull('superseded_at'))->with('guardianProfile.identity')->get()->map(fn (StudentGuardianRelationship $relationship): array => ['invoice_id' => (string) $invoice->public_id, 'invoice_number' => (string) $invoice->invoice_number, 'student_id' => (string) $invoice->student?->public_id, 'recipient_id' => (string) $relationship->guardianProfile?->identity?->public_id, 'amount_minor_units' => $balance, 'currency' => (string) $invoice->currency, 'due_date' => $this->date($invoice->due_date)])->all();
    }

    private function eligibleInvoice(FeeInvoice $invoice, int $schoolId): int
    {
        if ((int) $invoice->school_id !== $schoolId || (string) $invoice->status !== 'issued' || $invoice->student?->status !== 'active' || $invoice->enrollment?->status !== 'active') {
            throw new ModelNotFoundException;
        }
        $balance = $this->outstanding($invoice);
        if ($balance <= 0) {
            throw new ModelNotFoundException;
        }

        return $balance;
    }

    private function outstanding(FeeInvoice $invoice): int
    {
        $adjusted = (int) $invoice->adjustments()->where('status', 'applied')->sum('amount_minor_units');
        $allocated = (int) FeePaymentAllocation::query()->where('school_id', $invoice->school_id)->where('fee_invoice_id', $invoice->getKey())->where('status', 'allocated')->sum('amount_minor_units');

        return max(0, (int) $invoice->total_minor_units - $adjusted - $allocated);
    }

    private function relationship(int $schoolId, int $studentId, string $recipientId): StudentGuardianRelationship
    {
        $relationship = StudentGuardianRelationship::query()->where('school_id', $schoolId)->where('student_id', $studentId)->where('status', 'active')->whereNotNull('verified_at')->whereHas('guardianProfile.identity', fn ($query) => $query->where('public_id', $recipientId))->whereHas('guardianProfile.identity.contacts', fn ($query) => $query->whereNotNull('verified_at')->whereNull('superseded_at'))->first();
        if (! $relationship instanceof StudentGuardianRelationship) {
            throw new ModelNotFoundException;
        }

        return $relationship;
    }

    private function invoice(string $id, bool $lock = false): FeeInvoice
    {
        $query = FeeInvoice::query()->where('public_id', $id)->with(['student', 'enrollment', 'adjustments']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function reminder(string $id, bool $lock = false): FinanceReminder
    {
        $query = FinanceReminder::query()->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function data(FinanceReminder $reminder): array
    {
        return ['id' => (string) $reminder->public_id, 'invoice_id' => (string) $reminder->invoice?->public_id, 'invoice_number' => (string) $reminder->invoice?->invoice_number, 'amount_minor_units' => (int) $reminder->amount_minor_units, 'currency' => (string) $reminder->currency, 'due_date' => $this->date($reminder->due_date), 'reminder_type' => (string) $reminder->reminder_type, 'period_key' => (string) $reminder->period_key, 'status' => (string) $reminder->status, 'created_at' => $this->atom($reminder->created_at), 'queued_at' => $this->atom($reminder->queued_at), 'sent_at' => $this->atom($reminder->sent_at), 'failed_at' => $this->atom($reminder->failed_at), 'cancelled_at' => $this->atom($reminder->cancelled_at), 'failure_code' => $reminder->failure_code];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function recipientHash(int $schoolId, string $recipientId): string
    {
        return hash_hmac('sha256', 'finance-reminder-recipient|'.$schoolId.'|'.$recipientId, (string) config('app.key'));
    }

    private function auditRead(UserIdentity $actor, int $schoolId, int $count): void
    {
        $this->audit->execute(new AuditEventData(action: 'finance.reminders_read', subjectType: 'finance_reminder_projection', subjectPublicId: null, schoolId: $schoolId, actorId: $actor->getKey(), requestId: Context::get('request_id'), metadata: ['result_count' => $count], requiresSchoolContext: true));
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $transition */
    private function record(UserIdentity $actor, string $action, FinanceReminder $reminder, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'finance_reminder', subjectPublicId: (string) $reminder->public_id, schoolId: $context->schoolId, actorId: $actor->getKey(), requestId: Context::get('request_id'), stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')], requiresSchoolContext: true));
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : ($value === null ? null : (string) $value);
    }

    private function atom(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toAtomString() : ($value === null ? null : (string) $value);
    }

    /** @param LengthAwarePaginator<int, FeeInvoice> $paginator
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function meta(LengthAwarePaginator $paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()];
    }
}
