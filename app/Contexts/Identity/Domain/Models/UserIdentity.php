<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use App\Contexts\Identity\Domain\Enums\ContactType;
use Database\Factories\UserIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'password', 'public_id'])]
#[Hidden(['id', 'password', 'remember_token'])]
class UserIdentity extends Authenticatable
{
    protected $table = 'users';

    /** @use HasFactory<UserIdentityFactory> */
    use HasFactory, Notifiable;

    protected static function newFactory(): UserIdentityFactory
    {
        return UserIdentityFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $identity): void {
            $identity->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<UserContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(UserContact::class, 'user_id');
    }

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class, 'user_id');
    }

    /** @return HasOne<IdentitySecurityState, $this> */
    public function securityState(): HasOne
    {
        return $this->hasOne(IdentitySecurityState::class, 'user_id');
    }

    /** @return HasMany<SchoolMembership, $this> */
    public function schoolMemberships(): HasMany
    {
        return $this->hasMany(SchoolMembership::class, 'user_id');
    }

    /** @return HasMany<SchoolInvitation, $this> */
    public function schoolInvitations(): HasMany
    {
        return $this->hasMany(SchoolInvitation::class, 'invitee_id');
    }

    /** @return HasMany<PasswordResetChallenge, $this> */
    public function passwordResetChallenges(): HasMany
    {
        return $this->hasMany(PasswordResetChallenge::class, 'user_id');
    }

    /** @return HasMany<ContactVerificationChallenge, $this> */
    public function contactVerificationChallenges(): HasMany
    {
        return $this->hasMany(ContactVerificationChallenge::class, 'user_id');
    }

    /** @return HasMany<PlatformRoleAssignment, $this> */
    public function platformRoleAssignments(): HasMany
    {
        return $this->hasMany(PlatformRoleAssignment::class, 'user_id');
    }

    /** @return HasMany<BreakGlassAccessGrant, $this> */
    public function breakGlassGrants(): HasMany
    {
        return $this->hasMany(BreakGlassAccessGrant::class, 'granted_to_user_id');
    }

    /** @return HasMany<UserContact, $this> */
    public function activeContacts(): HasMany
    {
        return $this->contacts()->whereNull('superseded_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password');
    }

    public function primaryContact(ContactType $type): ?UserContact
    {
        return $this->activeContacts()
            ->where('type', $type->value)
            ->where('is_primary', true)
            ->first();
    }
}
