<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Contexts\Platform\Domain\Enums\RegistrationContactType;
use App\Contexts\Platform\Domain\Enums\SchoolRegistrationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'school_name', 'school_type', 'state', 'contact_type', 'canonical_contact',
    'consent_version', 'consented_at', 'status', 'request_id', 'request_ip_hash',
    'request_user_agent_hash',
])]
#[Hidden(['id', 'canonical_contact', 'request_ip_hash', 'request_user_agent_hash'])]
class SchoolRegistration extends Model
{
    /** @return HasMany<IdempotencyRecord, $this> */
    public function idempotencyRecords(): HasMany
    {
        return $this->hasMany(IdempotencyRecord::class, 'registration_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $registration): void {
            $registration->public_id ??= (string) Str::uuid7();
            $registration->status ??= SchoolRegistrationStatus::PendingVerification;
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contact_type' => RegistrationContactType::class,
            'status' => SchoolRegistrationStatus::class,
            'consented_at' => 'datetime',
        ];
    }
}
