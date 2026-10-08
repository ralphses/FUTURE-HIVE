<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\RegistrationVerificationCodeDelivery;
use App\Contexts\Platform\Domain\Enums\SchoolRegistrationStatus;
use App\Contexts\Platform\Domain\Exceptions\RegistrationVerificationFailed;
use App\Contexts\Platform\Domain\Models\RegistrationVerificationChallenge;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SchoolRegistrationVerificationAction
{
    public function __construct(private readonly RegistrationVerificationCodeDelivery $delivery) {}

    public function request(string $registrationPublicId, ?string $ipAddress, ?string $userAgent): void
    {
        $registration = SchoolRegistration::query()->where('public_id', $registrationPublicId)->first();

        if (! $registration instanceof SchoolRegistration
            || SchoolRegistrationStatus::tryFrom((string) $registration->getRawOriginal('status')) !== SchoolRegistrationStatus::PendingVerification) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($registration, $code, $ipAddress, $userAgent): RegistrationVerificationChallenge {
            $lockedRegistration = SchoolRegistration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            RegistrationVerificationChallenge::query()
                ->where('registration_id', $lockedRegistration->getKey())
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'replaced',
                ]);

            return RegistrationVerificationChallenge::create([
                'registration_id' => $lockedRegistration->getKey(),
                'code_hash' => hash('sha256', $code),
                'expires_at' => now()->addSeconds((int) config('auth.registration_verification.challenge_ttl', 600)),
                'max_attempts' => (int) config('auth.registration_verification.max_attempts', 5),
                'request_ip_hash' => $this->hashMetadata($ipAddress),
                'request_user_agent_hash' => $this->hashMetadata($userAgent),
            ]);
        });

        try {
            $this->delivery->send($registration, $code);
        } catch (Throwable) {
            $challenge->update([
                'revoked_at' => now(),
                'revoked_reason' => 'delivery_unavailable',
            ]);
        }
    }

    public function confirm(string $registrationPublicId, string $code): void
    {
        $failed = false;

        DB::transaction(function () use ($registrationPublicId, $code, &$failed): void {
            $registration = SchoolRegistration::query()
                ->where('public_id', $registrationPublicId)
                ->lockForUpdate()
                ->first();

            if (! $registration instanceof SchoolRegistration
                || SchoolRegistrationStatus::tryFrom((string) $registration->getRawOriginal('status')) !== SchoolRegistrationStatus::PendingVerification) {
                $failed = true;

                return;
            }

            $challenge = RegistrationVerificationChallenge::query()
                ->where('registration_id', $registration->getKey())
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $expiresAt = $challenge?->getAttribute('expires_at');
            $valid = $challenge instanceof RegistrationVerificationChallenge
                && $expiresAt instanceof Carbon
                && ! $expiresAt->isPast()
                && $challenge->attempts < $challenge->max_attempts
                && hash_equals((string) $challenge->getRawOriginal('code_hash'), hash('sha256', $code));

            if (! $valid) {
                if ($challenge instanceof RegistrationVerificationChallenge) {
                    $expired = $expiresAt instanceof Carbon && $expiresAt->isPast();
                    $nextAttempts = $challenge->attempts + 1;
                    $challenge->update([
                        'attempts' => $nextAttempts,
                        'revoked_at' => $expired || $nextAttempts >= $challenge->max_attempts ? now() : null,
                        'revoked_reason' => $expired ? 'expired' : ($nextAttempts >= $challenge->max_attempts ? 'attempt_limit' : null),
                    ]);
                }

                $failed = true;

                return;
            }

            $challenge->update(['consumed_at' => now()]);
            $registration->update(['status' => SchoolRegistrationStatus::Verified]);
        });

        if ($failed) {
            throw new RegistrationVerificationFailed;
        }
    }

    private function hashMetadata(?string $value): ?string
    {
        return $value === null ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
