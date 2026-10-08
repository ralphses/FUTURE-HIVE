<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\PasswordResetChallenge;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\AccountLockoutService;
use App\Contexts\Identity\Domain\Services\AuthenticationFailed;
use App\Contexts\Identity\Domain\Services\ContactCanonicalizer;
use App\Contexts\Identity\Domain\Services\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PasswordRecoveryAction
{
    public function __construct(
        private readonly ContactCanonicalizer $canonicalizer,
        private readonly PasswordResetCodeDelivery $delivery,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AccountLockoutService $lockout,
        private readonly RecordSecurityEventAction $securityEvents,
    ) {}

    public function request(string $login, ?string $ipAddress, ?string $userAgent): void
    {
        $issued = $this->issue($login, $ipAddress, $userAgent);

        if ($issued === null) {
            return;
        }

        [$identity, $contact, $code, $challenge] = $issued;

        try {
            $this->delivery->send($identity, $contact, $code);
        } catch (Throwable) {
            $challenge->update([
                'revoked_at' => now(),
                'revoked_reason' => 'delivery_unavailable',
            ]);
        }
    }

    public function issueForDevelopment(string $login): ?string
    {
        $issued = $this->issue($login, null, null);

        return $issued === null ? null : $issued[2];
    }

    public function reset(string $login, string $code, string $newPassword): void
    {
        $this->passwordPolicy->validate($newPassword);
        $contact = $this->findContact($login);

        if (! $contact instanceof UserContact) {
            throw new AuthenticationFailed;
        }

        $invalidCode = false;

        DB::transaction(function () use ($contact, $code, $newPassword, &$invalidCode): void {
            $challenge = PasswordResetChallenge::query()
                ->where('contact_id', $contact->id)
                ->where('purpose', 'password_reset')
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof PasswordResetChallenge) {
                throw new AuthenticationFailed;
            }

            $expiresAt = $challenge->getAttribute('expires_at');
            $attempts = (int) $challenge->getAttribute('attempts');
            $maxAttempts = (int) $challenge->getAttribute('max_attempts');
            $valid = $expiresAt !== null
                && ! $expiresAt->isPast()
                && $attempts < $maxAttempts
                && hash_equals((string) $challenge->getRawOriginal('code_hash'), hash('sha256', $code));

            if (! $valid) {
                $nextAttempts = $attempts + 1;
                $challenge->update([
                    'attempts' => $nextAttempts,
                    'revoked_at' => $nextAttempts >= $maxAttempts ? now() : null,
                    'revoked_reason' => $nextAttempts >= $maxAttempts ? 'attempt_limit' : null,
                ]);
                $invalidCode = true;

                return;
            }

            $identity = $challenge->identity()->lockForUpdate()->first();

            if (! $identity instanceof UserIdentity) {
                throw new AuthenticationFailed;
            }

            $identity->password = $newPassword;
            $identity->save();
            $this->lockout->clear($identity);
            $this->securityEvents->execute('password.reset', 'allowed', $identity);
            $identity->authSessions()->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => 'password_reset',
            ]);
            $challenge->update(['consumed_at' => now()]);
        });

        if ($invalidCode) {
            $this->securityEvents->execute('password.reset.failed', 'denied', $contact->identity()->first());
            throw new AuthenticationFailed;
        }
    }

    private function findContact(string $login): ?UserContact
    {
        foreach ([ContactType::Email, ContactType::Phone] as $type) {
            try {
                $canonical = $this->canonicalizer->canonicalize($type, $login);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $contact = UserContact::query()
                ->where('type', $type->value)
                ->where('canonical_value', $canonical)
                ->whereNull('superseded_at')
                ->first();

            if ($contact instanceof UserContact) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * @return array{0: UserIdentity, 1: UserContact, 2: string, 3: PasswordResetChallenge}|null
     */
    private function issue(string $login, ?string $ipAddress, ?string $userAgent): ?array
    {
        $contact = $this->findContact($login);

        if (! $contact instanceof UserContact || $contact->verified_at === null) {
            return null;
        }

        $identity = $contact->identity()->first();

        if (! $identity instanceof UserIdentity) {
            return null;
        }

        $code = (string) random_int(100000, 999999);
        $challenge = PasswordResetChallenge::create([
            'user_id' => $identity->id,
            'contact_id' => $contact->id,
            'purpose' => 'password_reset',
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addSeconds((int) config('auth.password_recovery.challenge_ttl', 900)),
            'max_attempts' => (int) config('auth.password_recovery.max_attempts', 5),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return [$identity, $contact, $code, $challenge];
    }
}
