<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use App\Contexts\Identity\Domain\Enums\ContactType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'canonical_value', 'verified_at', 'is_primary', 'superseded_at', 'superseded_reason'])]
#[Hidden(['id'])]
class UserContact extends Model
{
    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    /** @return HasMany<ContactVerificationChallenge, $this> */
    public function verificationChallenges(): HasMany
    {
        return $this->hasMany(ContactVerificationChallenge::class, 'contact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'verified_at' => 'datetime',
            'is_primary' => 'boolean',
            'superseded_at' => 'datetime',
        ];
    }
}
