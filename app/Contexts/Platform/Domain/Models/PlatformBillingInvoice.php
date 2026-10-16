<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'platform_subscription_id', 'platform_subscription_plan_id', 'invoice_number', 'plan_code_snapshot', 'plan_name_snapshot', 'plan_version_snapshot', 'amount_minor_units', 'currency', 'status', 'issued_at', 'due_at', 'paid_at', 'voided_at', 'overdue_at', 'idempotency_key_hash', 'request_fingerprint', 'created_by', 'voided_by', 'void_reason', 'request_id'])]
#[Hidden(['id', 'school_id', 'platform_subscription_id', 'platform_subscription_plan_id', 'idempotency_key_hash', 'request_fingerprint', 'created_by', 'voided_by', 'request_id'])]
final class PlatformBillingInvoice extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $invoice): void {
            $invoice->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'plan_version_snapshot' => 'integer',
            'amount_minor_units' => 'integer',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'overdue_at' => 'datetime',
        ];
    }
}
