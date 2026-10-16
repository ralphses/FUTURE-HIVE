<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'platform_billing_invoice_id', 'provider', 'amount_minor_units', 'currency', 'status', 'provider_reference_hash', 'idempotency_key_hash', 'request_fingerprint', 'completed_at', 'failed_at', 'cancelled_at', 'refunded_at', 'created_by', 'request_id'])]
#[Hidden(['id', 'school_id', 'platform_billing_invoice_id', 'provider_reference_hash', 'idempotency_key_hash', 'request_fingerprint', 'created_by', 'request_id'])]
final class PlatformBillingPayment extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $payment): void {
            $payment->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'amount_minor_units' => 'integer',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
