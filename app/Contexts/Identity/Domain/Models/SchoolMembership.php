<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'user_id', 'status', 'is_owner', 'joined_at', 'revoked_at', 'revoked_reason'])]
#[Hidden(['id'])]
class SchoolMembership extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $membership): void {
            $membership->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
            'joined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
