<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'platform_billing_payment_id', 'provider', 'event_id_hash', 'payload_fingerprint', 'event_type', 'status', 'request_id', 'observed_at'])]
#[Hidden(['id', 'school_id', 'platform_billing_payment_id', 'event_id_hash', 'payload_fingerprint', 'request_id'])]
final class PlatformBillingWebhookEvent extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            $event->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['observed_at' => 'datetime'];
    }
}
