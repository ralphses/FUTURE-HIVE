<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'name', 'organization', 'role', 'contact_type', 'contact_hash', 'category', 'message', 'consent_version', 'consented_at', 'status', 'idempotency_key_hash', 'fingerprint_hash', 'request_id', 'request_ip_hash', 'request_user_agent_hash'])]
#[Hidden(['id', 'contact_hash', 'idempotency_key_hash', 'fingerprint_hash', 'request_ip_hash', 'request_user_agent_hash', 'message', 'consent_version'])]
final class PublicDemoEnquiry extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $enquiry): void {
            $enquiry->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
        ];
    }
}
