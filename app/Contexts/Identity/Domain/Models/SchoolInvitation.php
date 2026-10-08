<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'school_id', 'inviter_id', 'invitee_id', 'invitee_contact_id', 'token_hash',
    'status', 'expires_at', 'accepted_at', 'revoked_at', 'revoked_reason',
])]
#[Hidden(['id', 'token_hash'])]
class SchoolInvitation extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'inviter_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'invitee_id');
    }

    /** @return BelongsTo<UserContact, $this> */
    public function inviteeContact(): BelongsTo
    {
        return $this->belongsTo(UserContact::class, 'invitee_contact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
