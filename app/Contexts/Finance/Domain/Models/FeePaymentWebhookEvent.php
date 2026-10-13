<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_payment_intent_id', 'provider', 'event_id_hash', 'payload_fingerprint', 'event_type', 'status', 'request_id', 'observed_at'])]
#[Hidden(['id', 'school_id', 'fee_payment_intent_id', 'event_id_hash', 'payload_fingerprint', 'request_id'])]
final class FeePaymentWebhookEvent extends Model
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

    /** @return BelongsTo<FeePaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(FeePaymentIntent::class, 'fee_payment_intent_id');
    }
}
