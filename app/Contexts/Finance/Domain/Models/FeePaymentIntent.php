<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_invoice_id', 'currency', 'amount_minor_units', 'status', 'idempotency_key_hash', 'request_fingerprint', 'payer_reference_hash', 'expires_at', 'created_by', 'cancelled_by', 'request_id', 'cancelled_at'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'idempotency_key_hash', 'request_fingerprint', 'payer_reference_hash', 'created_by', 'cancelled_by', 'request_id'])]
final class FeePaymentIntent extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $intent): void {
            $intent->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer', 'expires_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
