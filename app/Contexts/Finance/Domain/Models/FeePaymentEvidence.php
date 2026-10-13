<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_invoice_id', 'amount_minor_units', 'currency', 'payment_date', 'reference_hash', 'reference_last4', 'note', 'cloudinary_public_id', 'cloudinary_resource_type', 'format', 'status', 'submitted_by', 'reviewed_by', 'submitted_at', 'reviewed_at', 'review_reason', 'request_key_hash', 'request_fingerprint', 'request_id'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'reference_hash', 'cloudinary_public_id', 'cloudinary_resource_type', 'format', 'submitted_by', 'reviewed_by', 'request_key_hash', 'request_fingerprint', 'request_id'])]
final class FeePaymentEvidence extends Model
{
    use TenantScoped;

    protected $table = 'fee_payment_evidences';

    protected static function booted(): void
    {
        self::creating(function (self $evidence): void {
            $evidence->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer', 'payment_date' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
