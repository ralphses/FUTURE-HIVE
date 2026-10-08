<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\Contracts\ContactVerificationCodeDelivery;
use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\ContactVerificationChallenge;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\ContactCanonicalizer;
use App\Contexts\Identity\Domain\Services\VerificationFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ContactVerificationAction
{
    public function __construct(
        private readonly ContactCanonicalizer $canonicalizer,
        private readonly ContactVerificationCodeDelivery $delivery,
    ) {}

    public function request(string $value, ?string $ipAddress, ?string $userAgent): void
    {
        $contact = $this->findContact($value);

        if (! $contact instanceof UserContact || $contact->verified_at !== null) {
            return;
        }

        $identity = $contact->identity()->first();

        if (! $identity instanceof UserIdentity) {
            return;
        }

        ContactVerificationChallenge::query()
            ->where('contact_id', $contact->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => 'replaced',
            ]);

        $code = (string) random_int(100000, 999999);
        $challenge = ContactVerificationChallenge::create([
            'user_id' => $identity->id,
            'contact_id' => $contact->id,
            'purpose' => 'contact_verification',
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addSeconds((int) config('auth.contact_verification.challenge_ttl', 600)),
            'max_attempts' => (int) config('auth.contact_verification.max_attempts', 5),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        try {
            $this->delivery->send($identity, $contact, $code);
        } catch (Throwable) {
            $challenge->update([
                'revoked_at' => now(),
                'revoked_reason' => 'delivery_unavailable',
            ]);
        }
    }

    public function confirm(string $value, string $code): void
    {
        $contact = $this->findContact($value);

        if (! $contact instanceof UserContact) {
            throw new VerificationFailed;
        }

        $invalidCode = false;

        DB::transaction(function () use ($contact, $code, &$invalidCode): void {
            $challenge = ContactVerificationChallenge::query()
                ->where('contact_id', $contact->id)
                ->where('purpose', 'contact_verification')
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $expiresAt = $challenge?->getAttribute('expires_at');
            $valid = $challenge instanceof ContactVerificationChallenge
                && $expiresAt instanceof Carbon
                && ! $expiresAt->isPast()
                && $challenge->attempts < $challenge->max_attempts
                && hash_equals((string) $challenge->getRawOriginal('code_hash'), hash('sha256', $code));

            if (! $valid) {
                if ($challenge instanceof ContactVerificationChallenge) {
                    $nextAttempts = $challenge->attempts + 1;
                    $challenge->update([
                        'attempts' => $nextAttempts,
                        'revoked_at' => $nextAttempts >= $challenge->max_attempts ? now() : null,
                        'revoked_reason' => $nextAttempts >= $challenge->max_attempts ? 'attempt_limit' : null,
                    ]);
                }

                $invalidCode = true;

                return;
            }

            $contact->lockForUpdate()->update(['verified_at' => now()]);
            $challenge->update(['consumed_at' => now()]);
        });

        if ($invalidCode) {
            throw new VerificationFailed;
        }
    }

    private function findContact(string $value): ?UserContact
    {
        foreach ([ContactType::Email, ContactType::Phone] as $type) {
            try {
                $canonical = $this->canonicalizer->canonicalize($type, $value);
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
}
