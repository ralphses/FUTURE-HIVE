<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentEvidence;
use App\Contexts\Finance\Domain\Models\FeePaymentIntent;
use App\Contexts\Finance\Domain\Models\FeePaymentWebhookEvent;
use App\Contexts\Finance\Domain\Models\FinanceReceipt;
use App\Contexts\Finance\Domain\Models\FinanceReconciliationItem;
use App\Contexts\Finance\Domain\Models\FinanceReconciliationRun;
use App\Contexts\Finance\Domain\Models\FinancialLedgerBatch;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class FinanceReconciliationAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array<string, mixed> */
    public function list(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.reconciliation.read');

        return ['items' => FinanceReconciliationRun::query()->latest('id')->get()->map(fn (FinanceReconciliationRun $run): array => $this->runData($run))->all()];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'finance.reconciliation.manage');
        $context = TenantContext::require();
        /** @var list<array<string, mixed>> $sources */
        $sources = collect((array) $input['sources'])->sortBy(fn (array $source): string => (string) $source['source_type'].'|'.(string) $source['source_public_id'])->values()->all();
        $fingerprint = hash_hmac('sha256', json_encode($sources, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $context, $input, $sources, $fingerprint): array {
            $existing = FinanceReconciliationRun::query()->where('school_id', $context->schoolId)->where('source_fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($existing instanceof FinanceReconciliationRun) {
                return ['item' => $this->runData($existing->load('items'))];
            }
            $run = FinanceReconciliationRun::query()->create(['school_id' => $context->schoolId, 'source_fingerprint' => $fingerprint, 'status' => 'running', 'description' => $input['description'] ?? null, 'created_by' => $actor->getKey(), 'started_at' => now(), 'request_id' => Context::get('request_id')]);
            foreach ($sources as $source) {
                [$amount, $currency, $exception] = $this->source($context->schoolId, $source['source_type'], $source['source_public_id']);
                $inputAmount = (int) $source['amount_minor_units'];
                $inputCurrency = strtoupper($source['currency']);
                $exception ??= $amount === null ? 'SOURCE_NOT_FOUND' : ($amount !== $inputAmount ? 'AMOUNT_MISMATCH' : ($currency !== $inputCurrency ? 'CURRENCY_MISMATCH' : null));
                $run->items()->create(['school_id' => $context->schoolId, 'source_type' => $source['source_type'], 'source_public_id' => $source['source_public_id'], 'settlement_reference_hash' => isset($source['settlement_reference']) ? hash_hmac('sha256', $source['settlement_reference'], (string) config('app.key')) : null, 'amount_minor_units' => $amount, 'currency' => $currency, 'status' => $exception === null ? 'matched' : 'exception', 'exception_code' => $exception]);
            }
            $hasExceptions = $run->items()->where('status', 'exception')->exists();
            $run->update(['status' => $hasExceptions ? 'completed_with_exceptions' : 'completed', 'completed_at' => now()]);
            $this->record($actor, 'finance.reconciliation_created', $run, ['to' => $run->status, 'item_count' => count($sources)]);

            return ['item' => $this->runData($run->fresh('items'))];
        });
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.reconciliation.read');

        return ['item' => $this->runData($this->run($id)->load('items'))];
    }

    /** @return array<string, mixed> */
    public function items(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.reconciliation.read');

        return ['items' => $this->run($id)->items->map(fn (FinanceReconciliationItem $item): array => $this->itemData($item))->all()];
    }

    /** @return array<string, mixed> */
    public function resolve(UserIdentity $actor, string $school, string $runId, string $itemId, string $reason): array
    {
        $this->authorize($actor, $school, 'finance.reconciliation.resolve');

        return DB::transaction(function () use ($actor, $runId, $itemId, $reason): array {
            $item = FinanceReconciliationItem::query()->where('public_id', $itemId)->whereHas('run', fn ($query) => $query->where('public_id', $runId))->lockForUpdate()->firstOrFail();
            if ($item->status === 'resolved') {
                return ['item' => $this->itemData($item)];
            }
            if ($item->status !== 'exception') {
                throw new ModelNotFoundException;
            }
            $item->update(['status' => 'resolved', 'resolution_reason' => trim($reason), 'resolved_by' => $actor->getKey(), 'resolved_at' => now()]);
            $run = $item->run()->lockForUpdate()->firstOrFail();
            $run->update(['status' => $run->items()->where('status', 'exception')->exists() ? 'completed_with_exceptions' : 'completed']);
            $this->record($actor, 'finance.reconciliation_exception_resolved', $run, ['to' => 'resolved']);

            return ['item' => $this->itemData($item->fresh())];
        });
    }

    /** @return array<string, mixed> */
    public function cancel(UserIdentity $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'finance.reconciliation.manage');

        return DB::transaction(function () use ($actor, $id): array {
            $run = $this->run($id, true);
            if ($run->status !== 'cancelled') {
                if (! in_array($run->status, ['draft', 'running'], true)) {
                    throw new ModelNotFoundException;
                } $run->update(['status' => 'cancelled', 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()]);
                $this->record($actor, 'finance.reconciliation_cancelled', $run, ['to' => 'cancelled']);
            }

            return ['item' => $this->runData($run->fresh('items'))];
        });
    }

    /** @return array{0: ?int, 1: ?string, 2: ?string} */
    private function source(int $schoolId, string $type, string $id): array
    {
        $model = match ($type) {
            'payment_intent' => FeePaymentIntent::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'completed')->first(),
            'payment_webhook' => FeePaymentWebhookEvent::query()->where('school_id', $schoolId)->where('public_id', $id)->whereIn('status', ['accepted', 'processed', 'completed'])->first(),
            'payment_allocation' => FeePaymentAllocation::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'allocated')->first(),
            'payment_evidence' => FeePaymentEvidence::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'verified')->first(),
            'receipt' => FinanceReceipt::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'issued')->first(),
            'ledger_batch' => FinancialLedgerBatch::query()->where('school_id', $schoolId)->where('public_id', $id)->where('status', 'posted')->first(),
            default => null,
        };
        if ($model === null) {
            return [null, null, 'SOURCE_NOT_FOUND'];
        }
        if ($type === 'payment_webhook') {
            $intent = $model->intent()->where('school_id', $schoolId)->where('status', 'completed')->first();
            if ($intent === null) {
                return [null, null, 'SOURCE_NOT_VERIFIED'];
            }

            return [(int) $intent->amount_minor_units, (string) $intent->currency, null];
        }

        return [(int) ($model->amount_minor_units ?? ($model->entries()->where('direction', 'debit')->sum('amount_minor_units'))), (string) $model->currency, null];
    }

    private function run(string $id, bool $lock = false): FinanceReconciliationRun
    {
        $query = FinanceReconciliationRun::query()->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @param array<string, string|int> $transition */
    private function record(UserIdentity $actor, string $action, FinanceReconciliationRun $run, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'finance_reconciliation_run', subjectPublicId: (string) $run->public_id, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }

    /** @return array<string, mixed> */
    private function runData(FinanceReconciliationRun $run): array
    {
        return ['id' => (string) $run->public_id, 'status' => (string) $run->status, 'description' => $run->description, 'counts' => ['matched' => $run->items->where('status', 'matched')->count(), 'exceptions' => $run->items->where('status', 'exception')->count(), 'resolved' => $run->items->where('status', 'resolved')->count()], 'created_at' => $this->atom($run->created_at), 'completed_at' => $this->atom($run->completed_at), 'cancelled_at' => $this->atom($run->cancelled_at)];
    }

    /** @return array<string, mixed> */
    private function itemData(FinanceReconciliationItem $item): array
    {
        return ['id' => (string) $item->public_id, 'source_type' => (string) $item->source_type, 'source_id' => (string) $item->source_public_id, 'amount_minor_units' => $item->amount_minor_units === null ? null : (int) $item->amount_minor_units, 'currency' => $item->currency, 'status' => (string) $item->status, 'exception_code' => $item->exception_code, 'resolution_reason' => $item->resolution_reason, 'resolved_at' => $this->atom($item->resolved_at)];
    }

    private function atom(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toAtomString() : ($value === null ? null : (string) $value);
    }
}
