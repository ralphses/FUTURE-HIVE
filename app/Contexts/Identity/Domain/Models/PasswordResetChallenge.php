<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'user_id', 'contact_id', 'purpose', 'code_hash', 'expires_at', 'attempts',
    'max_attempts', 'consumed_at', 'revoked_at', 'revoked_reason', 'ip_address', 'user_agent',
])]
#[Hidden(['id', 'code_hash'])]
class PasswordResetChallenge extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $challenge): void {
            $challenge->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    /** @return BelongsTo<UserContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class, 'contact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }
}
