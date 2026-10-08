<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'key', 'label', 'description', 'scope', 'is_active'])]
#[Hidden(['id'])]
final class Permission extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $permission): void {
            $permission->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
