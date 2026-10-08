<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'granted_to_user_id', 'granted_by_user_id', 'school_id', 'resource_scope', 'purpose', 'approval_context', 'request_id', 'expires_at', 'revoked_at', 'revoked_reason'])]
#[Hidden(['id'])]
final class BreakGlassAccessGrant extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $grant): void {
            $grant->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function targetIdentity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'granted_to_user_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function grantingIdentity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'granted_by_user_id');
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    protected function casts(): array
    {
        return ['approval_context' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
