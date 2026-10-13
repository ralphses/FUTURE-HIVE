<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Application\Actions;

use App\Contexts\Finance\Domain\Models\FeeInvoice;
use App\Contexts\Finance\Domain\Models\FeePaymentAllocation;
use App\Contexts\Finance\Domain\Models\FeePaymentEvidence;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Files\CloudinaryAssetStore;
use App\Support\Files\SchoolFileReference;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FeePaymentEvidenceAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly CloudinaryAssetStore $files,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function list(UserIdentity $actor, string $school, string $invoiceId): array
    {
        $this->authorize($actor, $school, 'finance.payment-evidence.read');
        $invoice = $this->invoice($invoiceId);

        return ['items' => FeePaymentEvidence::query()->where('fee_invoice_id', $invoice->getKey())->with('invoice')->orderBy('id')->get()->map(fn (FeePaymentEvidence $evidence): array => $this->data($evidence))->all()];
    }

    /** @param array{amount_minor_units: int, currency: string, payment_date: string, reference: string, note?: string|null, evidence_file?: UploadedFile|null} $input
     * @return array{payment_evidence: array<string, mixed>}
     */
    public function create(UserIdentity $actor, string $school, string $invoiceId, array $input): array
    {
        $this->authorize($actor, $school, 'finance.payment-evidence.manage');
        $context = TenantContext::require();
        $reference = trim((string) $input['reference']);
        $referenceHash = hash_hmac('sha256', 'finance-payment-evidence|'.$context->schoolId.'|'.strtoupper($reference), (string) config('app.key'));
        $fingerprint = hash_hmac('sha256', json_encode(['invoice' => $invoiceId, 'amount' => (int) $input['amount_minor_units'], 'currency' => strtoupper((string) $input['currency']), 'payment_date' => (string) $input['payment_date'], 'reference' => strtoupper($reference), 'note' => $input['note'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
        $requestKey = trim((string) request()->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $requestKey)) {
            throw ValidationException::withMessages(['idempotency_key' => ['The Idempotency-Key header must be 16 to 128 safe characters.']]);
        }
        $keyHash = hash_hmac('sha256', 'finance-payment-evidence|'.$context->schoolId.'|'.$requestKey, (string) config('app.key'));

        return DB::transaction(function () use ($actor, $context, $invoiceId, $input, $reference, $referenceHash, $fingerprint, $keyHash): array {
            $existing = FeePaymentEvidence::query()->where('school_id', $context->schoolId)->where('request_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing instanceof FeePaymentEvidence) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key' => ['The idempotency key was already used for a different request.']]);
                }

                return ['payment_evidence' => $this->data($existing->load('invoice'))];
            }
            $invoice = $this->invoice($invoiceId, true);
            if ($invoice->status !== 'issued') {
                throw new ModelNotFoundException;
            }
            $currency = strtoupper((string) $input['currency']);
            if ($currency !== (string) $invoice->currency) {
                throw ValidationException::withMessages(['currency' => ['The evidence currency must match the invoice currency.']]);
            }
            $amount = (int) $input['amount_minor_units'];
            $allocated = (int) FeePaymentAllocation::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->sum('amount_minor_units');
            $evidenced = (int) FeePaymentEvidence::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->whereIn('status', ['pending', 'under_review', 'verified'])->sum('amount_minor_units');
            if ($allocated + $evidenced + $amount > (int) $invoice->total_minor_units) {
                throw ValidationException::withMessages(['amount_minor_units' => ['The evidence exceeds the safe outstanding invoice amount.']]);
            }
            if (FeePaymentEvidence::query()->where('school_id', $context->schoolId)->where('fee_invoice_id', $invoice->getKey())->where('reference_hash', $referenceHash)->exists()) {
                throw ValidationException::withMessages(['reference' => ['The payment evidence reference has already been recorded.']]);
            }
            $file = $input['evidence_file'] ?? null;
            $fileReference = $file instanceof UploadedFile ? $this->files->store($file, (string) $context->schoolPublicId) : null;
            $evidence = FeePaymentEvidence::query()->create([
                'school_id' => $context->schoolId,
                'fee_invoice_id' => $invoice->getKey(),
                'amount_minor_units' => $amount,
                'currency' => $currency,
                'payment_date' => $input['payment_date'],
                'reference_hash' => $referenceHash,
                'reference_last4' => mb_substr($reference, -4),
                'note' => $input['note'] ?? null,
                'cloudinary_public_id' => $fileReference?->publicId,
                'cloudinary_resource_type' => $fileReference?->resourceType,
                'format' => $fileReference?->format,
                'status' => 'pending',
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'request_key_hash' => $keyHash,
                'request_fingerprint' => $fingerprint,
                'request_id' => Context::get('request_id'),
            ]);
            $this->record($actor, 'finance.payment_evidence_created', $evidence, ['to' => 'pending']);

            return ['payment_evidence' => $this->data($evidence->load('invoice'))];
        });
    }

    /** @return array{payment_evidence: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $invoiceId, string $evidenceId): array
    {
        $this->authorize($actor, $school, 'finance.payment-evidence.read');
        $invoice = $this->invoice($invoiceId);
        $evidence = FeePaymentEvidence::query()->where('fee_invoice_id', $invoice->getKey())->where('public_id', $evidenceId)->with('invoice')->firstOrFail();

        return ['payment_evidence' => $this->data($evidence)];
    }

    /** @return array{payment_evidence: array<string, mixed>} */
    public function verify(UserIdentity $actor, string $school, string $invoiceId, string $evidenceId): array
    {
        $this->authorize($actor, $school, 'finance.payment-evidence.review');

        return $this->transition($actor, $invoiceId, $evidenceId, 'verified', null);
    }

    /** @return array{payment_evidence: array<string, mixed>} */
    public function reject(UserIdentity $actor, string $school, string $invoiceId, string $evidenceId, string $reason): array
    {
        $this->authorize($actor, $school, 'finance.payment-evidence.review');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 240 || preg_match('/^[A-Za-z0-9 .,\'\"()_:-]+$/', $reason) !== 1) {
            throw ValidationException::withMessages(['reason' => ['The rejection reason is invalid.']]);
        }

        return $this->transition($actor, $invoiceId, $evidenceId, 'rejected', $reason);
    }

    /** @return array{payment_evidence: array<string, mixed>} */
    public function cancel(UserIdentity $actor, string $school, string $invoiceId, string $evidenceId): array
    {
        $this->authorize($actor, $school, 'finance.payment-evidence.manage');

        return $this->transition($actor, $invoiceId, $evidenceId, 'cancelled', null);
    }

    /** @return array{payment_evidence: array<string, mixed>} */
    private function transition(UserIdentity $actor, string $invoiceId, string $evidenceId, string $status, ?string $reason): array
    {
        return DB::transaction(function () use ($actor, $invoiceId, $evidenceId, $status, $reason): array {
            $invoice = $this->invoice($invoiceId, true);
            $evidence = FeePaymentEvidence::query()->where('fee_invoice_id', $invoice->getKey())->where('public_id', $evidenceId)->lockForUpdate()->firstOrFail();
            if ($evidence->status === $status) {
                return ['payment_evidence' => $this->data($evidence->load('invoice'))];
            }
            if (! in_array((string) $evidence->status, ['pending', 'under_review'], true) || ($status === 'verified' && $invoice->status !== 'issued')) {
                throw new ModelNotFoundException;
            }
            $from = (string) $evidence->status;
            $evidence->update(['status' => $status, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_reason' => $reason]);
            $this->record($actor, 'finance.payment_evidence_'.$status, $evidence, ['from' => $from, 'to' => $status]);

            return ['payment_evidence' => $this->data($evidence->fresh('invoice'))];
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function invoice(string $invoiceId, bool $lock = false): FeeInvoice
    {
        $query = FeeInvoice::query()->where('public_id', $invoiceId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array{id: string, invoice_id: string, amount_minor_units: int, currency: string, payment_date: ?string, reference_last4: string, note: ?string, status: string, review_reason: ?string, download_url: ?string, submitted_at: ?string, reviewed_at: ?string, created_at: ?string} */
    private function data(FeePaymentEvidence $evidence): array
    {
        $downloadUrl = null;
        if ($evidence->cloudinary_public_id !== null && $evidence->format !== null && $evidence->cloudinary_resource_type !== null) {
            $downloadUrl = $this->files->temporaryDownloadUrl(new SchoolFileReference((string) TenantContext::require()->schoolPublicId, (string) $evidence->cloudinary_public_id, (string) $evidence->format, (string) $evidence->cloudinary_resource_type));
        }

        $paymentDate = CarbonImmutable::parse((string) $evidence->payment_date)->toDateString();

        return ['id' => (string) $evidence->public_id, 'invoice_id' => (string) $evidence->invoice?->public_id, 'amount_minor_units' => (int) $evidence->amount_minor_units, 'currency' => (string) $evidence->currency, 'payment_date' => $paymentDate, 'reference_last4' => (string) $evidence->reference_last4, 'note' => $evidence->note, 'status' => (string) $evidence->status, 'review_reason' => $evidence->review_reason, 'download_url' => $downloadUrl, 'submitted_at' => $this->timestamp($evidence->submitted_at), 'reviewed_at' => $this->timestamp($evidence->reviewed_at), 'created_at' => $this->timestamp($evidence->created_at)];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toAtomString() : ($value === null ? null : (string) $value);
    }

    /** @param array<string, mixed> $transition */
    private function record(UserIdentity $actor, string $action, FeePaymentEvidence $evidence, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'fee_payment_evidence', subjectPublicId: (string) $evidence->public_id, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')], requiresSchoolContext: true));
    }
}
