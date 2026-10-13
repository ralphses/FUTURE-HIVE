<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Application\Services\FinancePeriodGuard;
use App\Contexts\Finance\Domain\Models\FeeAdjustmentProgram;
use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeeInvoiceAdjustment;
use App\Contexts\Finance\Domain\Models\FeeInvoiceLine;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FeeAdjustmentAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{programs: list<array<string, mixed>>} */
    public function programs(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.read');

        return ['programs' => FeeAdjustmentProgram::query()->orderBy('name')->get()->map(fn (FeeAdjustmentProgram $program): array => $this->programData($program))->all()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{program: array<string, mixed>}
     */
    public function createProgram(UserIdentity $actor, string $school, array $data): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.manage');
        $context = TenantContext::require();
        $basisPoints = $this->basisPoints($data['percentage'] ?? null);

        if ($data['calculation_method'] === 'fixed' && ! isset($data['fixed_amount_minor_units'])) {
            throw ValidationException::withMessages(['fixed_amount_minor_units' => ['A fixed adjustment amount is required.']]);
        }
        if ($data['calculation_method'] === 'percentage' && $basisPoints === null) {
            throw ValidationException::withMessages(['percentage' => ['A percentage adjustment is required.']]);
        }

        return DB::transaction(function () use ($actor, $context, $data, $basisPoints): array {
            if (FeeAdjustmentProgram::query()->where('school_id', $context->schoolId)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => ['The adjustment code is already in use.']]);
            }
            $program = FeeAdjustmentProgram::query()->create(['school_id' => $context->schoolId, 'code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'adjustment_type' => $data['adjustment_type'], 'calculation_method' => $data['calculation_method'], 'fixed_amount_minor_units' => $data['calculation_method'] === 'fixed' ? $data['fixed_amount_minor_units'] : null, 'percentage_basis_points' => $data['calculation_method'] === 'percentage' ? $basisPoints : null, 'allows_multiple' => $data['allows_multiple'] ?? false, 'eligibility' => $data['eligibility'] ?? null, 'status' => 'draft', 'created_by' => $actor->getKey()]);
            $this->record($actor, 'finance.adjustment_program_created', 'fee_adjustment_program', (string) $program->public_id, ['to' => 'draft']);

            return ['program' => $this->programData($program)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{program: array<string, mixed>}
     */
    public function updateProgram(UserIdentity $actor, string $school, string $programId, array $data): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.manage');
        $program = $this->program($programId, true);
        if ($program->status !== 'draft') {
            throw ValidationException::withMessages(['program' => ['Only draft adjustment programs can be edited.']]);
        }
        $updates = array_filter($data, static fn (mixed $value, string $key): bool => ! in_array($key, ['percentage', 'calculation_method', 'fixed_amount_minor_units'], true) && $value !== null, ARRAY_FILTER_USE_BOTH);
        $method = $data['calculation_method'] ?? $program->calculation_method;
        $basisPoints = array_key_exists('percentage', $data) ? $this->basisPoints($data['percentage']) : $program->percentage_basis_points;
        $fixedAmount = array_key_exists('fixed_amount_minor_units', $data) ? $data['fixed_amount_minor_units'] : $program->fixed_amount_minor_units;
        if ($method === 'fixed' && $fixedAmount === null) {
            throw ValidationException::withMessages(['fixed_amount_minor_units' => ['A fixed adjustment amount is required.']]);
        }
        if ($method === 'percentage' && $basisPoints === null) {
            throw ValidationException::withMessages(['percentage' => ['A percentage adjustment is required.']]);
        }
        $updates['calculation_method'] = $method;
        $updates['fixed_amount_minor_units'] = $method === 'fixed' ? $fixedAmount : null;
        $updates['percentage_basis_points'] = $method === 'percentage' ? $basisPoints : null;
        $program->update($updates);

        return ['program' => $this->programData($program->fresh())];
    }

    /** @return array{program: array<string, mixed>} */
    public function activateProgram(UserIdentity $actor, string $school, string $programId): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.manage');
        $program = $this->program($programId, true);
        if ($program->status === 'active') {
            return ['program' => $this->programData($program)];
        }
        if ($program->status !== 'draft') {
            throw ValidationException::withMessages(['program' => ['Only draft adjustment programs can be activated.']]);
        }
        $program->update(['status' => 'active', 'activated_by' => $actor->getKey(), 'activated_at' => now()]);
        $this->record($actor, 'finance.adjustment_program_activated', 'fee_adjustment_program', (string) $program->public_id, ['from' => 'draft', 'to' => 'active']);

        return ['program' => $this->programData($program->fresh())];
    }

    /** @return array{program: array<string, mixed>} */
    public function retireProgram(UserIdentity $actor, string $school, string $programId): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.manage');
        $program = $this->program($programId, true);
        if ($program->status === 'retired') {
            return ['program' => $this->programData($program)];
        }
        if ($program->status !== 'active') {
            throw ValidationException::withMessages(['program' => ['Only active adjustment programs can be retired.']]);
        }
        $program->update(['status' => 'retired', 'retired_by' => $actor->getKey(), 'retired_at' => now()]);
        $this->record($actor, 'finance.adjustment_program_retired', 'fee_adjustment_program', (string) $program->public_id, ['from' => 'active', 'to' => 'retired']);

        return ['program' => $this->programData($program->fresh())];
    }

    /** @return array{adjustments: list<array<string, mixed>>} */
    public function adjustments(UserIdentity $actor, string $school, string $invoiceId): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.read');
        $invoice = $this->invoice($invoiceId);

        return ['adjustments' => $invoice->adjustments()->with('program')->latest('id')->get()->map(fn (FeeInvoiceAdjustment $adjustment): array => $this->adjustmentData($adjustment))->all()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{adjustment: array<string, mixed>}
     */
    public function request(UserIdentity $actor, string $school, string $invoiceId, array $data): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $context, $invoiceId, $data): array {
            $invoice = $this->invoice($invoiceId, true);
            FinancePeriodGuard::assertOpenForInvoice($invoice);
            if ($invoice->status !== 'draft' || $invoice->student?->status !== 'active' || $invoice->enrollment?->status !== 'active') {
                throw new ModelNotFoundException;
            }
            $program = FeeAdjustmentProgram::query()->where('public_id', $data['program_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            $line = null;
            if (($data['line_id'] ?? null) !== null) {
                $line = $invoice->lines()->where('public_id', $data['line_id'])->firstOrFail();
            }
            $existingQuery = $invoice->adjustments()->whereIn('status', ['pending', 'approved', 'applied'])->where('adjustment_type', $program->adjustment_type);
            $existingQuery = $line instanceof FeeInvoiceLine ? $existingQuery->where('fee_invoice_line_id', $line->getKey()) : $existingQuery->whereNull('fee_invoice_line_id');
            $existing = $existingQuery->lockForUpdate()->first();
            if ($existing instanceof FeeInvoiceAdjustment && ! $program->allows_multiple) {
                return ['adjustment' => $this->adjustmentData($existing->load('program'))];
            }
            $base = $line instanceof FeeInvoiceLine ? (int) $line->amount_minor_units : (int) $invoice->total_minor_units;
            $amount = $program->calculation_method === 'fixed' ? (int) $program->fixed_amount_minor_units : intdiv($base * (int) $program->percentage_basis_points + 5000, 10000);
            if ($amount < 0 || $amount > $base) {
                throw ValidationException::withMessages(['adjustment' => ['The adjustment cannot exceed the eligible amount.']]);
            }
            $adjustment = FeeInvoiceAdjustment::query()->create(['school_id' => $context->schoolId, 'fee_invoice_id' => $invoice->getKey(), 'fee_invoice_line_id' => $line?->getKey(), 'fee_adjustment_program_id' => $program->getKey(), 'adjustment_type' => $program->adjustment_type, 'calculation_method' => $program->calculation_method, 'base_minor_units' => $base, 'amount_minor_units' => $amount, 'percentage_basis_points' => $program->percentage_basis_points, 'status' => 'pending', 'reason' => $data['reason'], 'requested_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $this->record($actor, 'finance.adjustment_requested', 'fee_invoice_adjustment', (string) $adjustment->public_id, ['to' => 'pending']);

            return ['adjustment' => $this->adjustmentData($adjustment->load('program'))];
        });
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function approve(UserIdentity $actor, string $school, string $invoiceId, string $adjustmentId): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.approve');
        $adjustment = $this->adjustment($invoiceId, $adjustmentId, true);
        if ($adjustment->status === 'approved' || $adjustment->status === 'applied') {
            return ['adjustment' => $this->adjustmentData($adjustment->load('program'))];
        }
        if ($adjustment->status !== 'pending') {
            throw ValidationException::withMessages(['adjustment' => ['Only pending adjustments can be approved.']]);
        }
        $adjustment->update(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now()]);
        $this->record($actor, 'finance.adjustment_approved', 'fee_invoice_adjustment', (string) $adjustment->public_id, ['from' => 'pending', 'to' => 'approved']);

        return ['adjustment' => $this->adjustmentData($adjustment->fresh()->load('program'))];
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function reject(UserIdentity $actor, string $school, string $invoiceId, string $adjustmentId, string $reason): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.approve');
        $adjustment = $this->adjustment($invoiceId, $adjustmentId, true);
        if ($adjustment->status === 'rejected') {
            return ['adjustment' => $this->adjustmentData($adjustment->load('program'))];
        }
        if (! in_array($adjustment->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['adjustment' => ['This adjustment cannot be rejected.']]);
        }
        $adjustment->update(['status' => 'rejected', 'reason' => $reason, 'rejected_by' => $actor->getKey(), 'rejected_at' => now()]);
        $this->record($actor, 'finance.adjustment_rejected', 'fee_invoice_adjustment', (string) $adjustment->public_id, ['from' => 'pending', 'to' => 'rejected']);

        return ['adjustment' => $this->adjustmentData($adjustment->fresh()->load('program'))];
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function apply(UserIdentity $actor, string $school, string $invoiceId, string $adjustmentId): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.apply');

        return DB::transaction(function () use ($actor, $invoiceId, $adjustmentId): array {
            $adjustment = $this->adjustment($invoiceId, $adjustmentId, true)->load('invoice');
            if ($adjustment->status === 'applied') {
                return ['adjustment' => $this->adjustmentData($adjustment->load('program'))];
            }
            if ($adjustment->status !== 'approved' || $adjustment->invoice->status !== 'draft') {
                throw ValidationException::withMessages(['adjustment' => ['Only approved adjustments for draft invoices can be applied.']]);
            }
            $invoice = FeeInvoice::query()->whereKey($adjustment->fee_invoice_id)->lockForUpdate()->firstOrFail();
            FinancePeriodGuard::assertOpenForInvoice($invoice);
            $newTotal = (int) $invoice->total_minor_units - (int) $adjustment->amount_minor_units;
            if ($newTotal < 0) {
                throw ValidationException::withMessages(['adjustment' => ['The adjustment cannot reduce the invoice below zero.']]);
            }
            $invoice->update(['total_minor_units' => $newTotal]);
            DB::table('fee_assessment_items')->where('fee_invoice_id', $invoice->getKey())->update(['total_minor_units' => $newTotal]);
            $adjustment->update(['status' => 'applied', 'applied_by' => $actor->getKey(), 'applied_at' => now()]);
            $this->record($actor, 'finance.adjustment_applied', 'fee_invoice_adjustment', (string) $adjustment->public_id, ['from' => 'approved', 'to' => 'applied', 'amount_minor_units' => (int) $adjustment->amount_minor_units]);

            return ['adjustment' => $this->adjustmentData($adjustment->fresh()->load('program'))];
        });
    }

    /** @return array{adjustment: array<string, mixed>} */
    public function cancel(UserIdentity $actor, string $school, string $invoiceId, string $adjustmentId, string $reason): array
    {
        $this->authorize($actor, $school, 'finance.adjustments.manage');
        $adjustment = $this->adjustment($invoiceId, $adjustmentId, true);
        if ($adjustment->status === 'cancelled') {
            return ['adjustment' => $this->adjustmentData($adjustment->load('program'))];
        }
        if (! in_array($adjustment->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['adjustment' => ['Only pending or approved adjustments can be cancelled.']]);
        }
        $adjustment->update(['status' => 'cancelled', 'reason' => $reason, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()]);
        $this->record($actor, 'finance.adjustment_cancelled', 'fee_invoice_adjustment', (string) $adjustment->public_id, ['to' => 'cancelled']);

        return ['adjustment' => $this->adjustmentData($adjustment->fresh()->load('program'))];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function program(string $id, bool $lock = false): FeeAdjustmentProgram
    {
        $query = FeeAdjustmentProgram::query()->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function invoice(string $id, bool $lock = false): FeeInvoice
    {
        $query = FeeInvoice::query()->where('public_id', $id)->with(['student', 'enrollment']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function adjustment(string $invoiceId, string $id, bool $lock = false): FeeInvoiceAdjustment
    {
        $query = FeeInvoiceAdjustment::query()->where('public_id', $id)->whereHas('invoice', fn ($invoice) => $invoice->where('public_id', $invoiceId));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function basisPoints(mixed $percentage): ?int
    {
        if ($percentage === null || $percentage === '') {
            return null;
        }
        $value = number_format((float) $percentage, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** @return array<string, mixed> */
    private function programData(FeeAdjustmentProgram $program): array
    {
        return ['id' => (string) $program->public_id, 'code' => $program->code, 'name' => $program->name, 'description' => $program->description, 'adjustment_type' => $program->adjustment_type, 'calculation_method' => $program->calculation_method, 'fixed_amount_minor_units' => $program->fixed_amount_minor_units, 'percentage' => $program->percentage_basis_points === null ? null : $program->percentage_basis_points / 100, 'allows_multiple' => (bool) $program->allows_multiple, 'eligibility' => $program->eligibility, 'status' => $program->status, 'activated_at' => $this->timestamp($program->activated_at), 'retired_at' => $this->timestamp($program->retired_at)];
    }

    /** @return array<string, mixed> */
    private function adjustmentData(FeeInvoiceAdjustment $adjustment): array
    {
        return ['id' => (string) $adjustment->public_id, 'invoice_id' => (string) $adjustment->invoice?->public_id, 'program_id' => (string) $adjustment->program?->public_id, 'adjustment_type' => $adjustment->adjustment_type, 'calculation_method' => $adjustment->calculation_method, 'base_minor_units' => (int) $adjustment->base_minor_units, 'amount_minor_units' => (int) $adjustment->amount_minor_units, 'percentage' => $adjustment->percentage_basis_points === null ? null : $adjustment->percentage_basis_points / 100, 'status' => $adjustment->status, 'reason' => $adjustment->reason, 'requested_at' => $this->timestamp($adjustment->created_at), 'approved_at' => $this->timestamp($adjustment->approved_at), 'applied_at' => $this->timestamp($adjustment->applied_at)];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    /** @param array<string, mixed> $transition */
    private function record(UserIdentity $actor, string $action, string $subjectType, string $subjectId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: $subjectType, subjectPublicId: $subjectId, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
