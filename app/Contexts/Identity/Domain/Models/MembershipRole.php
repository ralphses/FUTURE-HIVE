<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Database\Factories\MembershipRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_membership_id', 'role_id', 'assigned_by', 'assigned_at', 'revoked_at', 'revoked_reason'])]
#[Hidden(['id'])]
final class MembershipRole extends Model
{
    /** @use HasFactory<MembershipRoleFactory> */
    use HasFactory;

    protected static function newFactory(): MembershipRoleFactory
    {
        return MembershipRoleFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $assignment): void {
            $assignment->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
