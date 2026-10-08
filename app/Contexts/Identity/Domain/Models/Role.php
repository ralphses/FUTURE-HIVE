<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'key', 'label', 'description', 'scope', 'is_active'])]
#[Hidden(['id'])]
final class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $role): void {
            $role->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /** @return HasMany<MembershipRole, $this> */
    public function membershipRoles(): HasMany
    {
        return $this->hasMany(MembershipRole::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
