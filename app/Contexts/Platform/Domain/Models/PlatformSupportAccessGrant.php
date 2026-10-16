<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'support_case_id', 'purpose', 'scope', 'status', 'requested_by', 'approved_by', 'revoked_by', 'requested_at', 'approved_at', 'expires_at', 'revoked_at', 'idempotency_key_hash', 'request_fingerprint', 'request_id'])]
#[Hidden(['id', 'school_id', 'support_case_id', 'requested_by', 'approved_by', 'revoked_by', 'idempotency_key_hash', 'request_fingerprint', 'request_id'])]
final class PlatformSupportAccessGrant extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $grant): void {
            $grant->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
