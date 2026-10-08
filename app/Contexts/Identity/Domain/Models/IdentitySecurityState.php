<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property int $failed_login_attempts
 * @property Carbon|null $failure_window_started_at
 * @property int $lockout_level
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_failed_login_at
 * @property Carbon|null $last_successful_login_at
 */
#[Fillable([
    'public_id', 'user_id', 'failed_login_attempts', 'failure_window_started_at', 'lockout_level',
    'locked_until', 'last_failed_login_at', 'last_successful_login_at',
])]
#[Hidden(['id'])]
class IdentitySecurityState extends Model
{
    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $state): void {
            $state->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'failed_login_attempts' => 'integer',
            'failure_window_started_at' => 'datetime',
            'lockout_level' => 'integer',
            'locked_until' => 'datetime',
            'last_failed_login_at' => 'datetime',
            'last_successful_login_at' => 'datetime',
        ];
    }
}
