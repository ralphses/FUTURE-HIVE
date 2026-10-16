<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'notification_template_id', 'channel', 'recipient_public_id', 'recipient_reference_hash', 'recipient_type', 'source_type', 'source_public_id', 'idempotency_hash', 'status', 'failure_code', 'created_by', 'request_id', 'queued_at', 'sent_at', 'failed_at', 'cancelled_at'])]
#[Hidden(['id', 'school_id', 'notification_template_id', 'recipient_reference_hash', 'idempotency_hash', 'created_by', 'request_id'])]
final class NotificationDelivery extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $delivery): void {
            $delivery->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }
}
