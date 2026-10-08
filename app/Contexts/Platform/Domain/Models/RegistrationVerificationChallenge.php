<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'registration_id', 'code_hash', 'expires_at', 'attempts', 'max_attempts',
    'consumed_at', 'revoked_at', 'revoked_reason', 'request_ip_hash', 'request_user_agent_hash',
])]
#[Hidden(['id', 'code_hash', 'request_ip_hash', 'request_user_agent_hash'])]
class RegistrationVerificationChallenge extends Model
{
    /** @return BelongsTo<SchoolRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(SchoolRegistration::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $challenge): void {
            $challenge->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
