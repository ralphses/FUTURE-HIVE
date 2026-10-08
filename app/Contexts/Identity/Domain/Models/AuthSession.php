<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'user_id', 'token_family_id', 'refresh_token_hash', 'expires_at',
    'last_used_at', 'revoked_at', 'revoked_reason', 'replaced_by_id', 'ip_address', 'user_agent',
])]
#[Hidden(['id', 'refresh_token_hash'])]
class AuthSession extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->public_id ??= (string) Str::uuid7();
            $session->token_family_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
