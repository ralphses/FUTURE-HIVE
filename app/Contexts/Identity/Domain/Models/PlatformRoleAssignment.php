<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'user_id', 'role_id', 'assigned_by', 'assigned_at', 'revoked_by', 'revoked_at', 'revoked_reason'])]
#[Hidden(['id'])]
final class PlatformRoleAssignment extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $assignment): void {
            $assignment->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
