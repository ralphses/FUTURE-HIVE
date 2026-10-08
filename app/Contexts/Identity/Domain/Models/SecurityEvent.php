<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'user_id', 'event_type', 'outcome', 'request_id', 'login_hash', 'ip_hash',
    'user_agent_hash', 'context',
])]
#[Hidden(['id', 'login_hash', 'ip_hash', 'user_agent_hash'])]
class SecurityEvent extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->public_id ??= (string) Str::uuid7();
            $event->created_at ??= now();
        });
    }

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
