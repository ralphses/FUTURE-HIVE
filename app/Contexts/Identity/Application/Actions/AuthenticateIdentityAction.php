<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\AuthenticationResult;
use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\AuthenticationFailed;
use App\Contexts\Identity\Domain\Services\ContactCanonicalizer;
use App\Contexts\Identity\Infrastructure\Authentication\JwtTokenService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AuthenticateIdentityAction
{
    public function __construct(
        private readonly ContactCanonicalizer $canonicalizer,
        private readonly JwtTokenService $jwt,
    ) {}

    public function login(string $login, string $password, ?string $ipAddress, ?string $userAgent): AuthenticationResult
    {
        $identity = $this->findIdentity($login);

        if ($identity === null || $identity->password === null || ! Hash::check($password, $identity->password)) {
            throw new AuthenticationFailed;
        }

        return DB::transaction(function () use ($identity, $ipAddress, $userAgent): AuthenticationResult {
            $refreshToken = Str::random(96);
            $session = AuthSession::create([
                'user_id' => $identity->id,
                'token_family_id' => (string) Str::uuid7(),
                'refresh_token_hash' => hash('sha256', $refreshToken),
                'expires_at' => now()->addSeconds((int) config('auth.jwt.refresh_ttl', 1209600)),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            return new AuthenticationResult($this->jwt->issue($identity, $session), $refreshToken, $session);
        });
    }

    public function refresh(string $refreshToken, ?string $ipAddress, ?string $userAgent): AuthenticationResult
    {
        $reuseDetected = false;
        $result = DB::transaction(function () use ($refreshToken, $ipAddress, $userAgent, &$reuseDetected): ?AuthenticationResult {
            $session = AuthSession::query()
                ->where('refresh_token_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                throw new AuthenticationFailed;
            }

            if ($session->revoked_at !== null) {
                $tokenFamilyId = (string) $session->getRawOriginal('token_family_id');
                AuthSession::query()->where('token_family_id', $tokenFamilyId)->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'refresh_token_reuse',
                ]);
                $reuseDetected = true;

                return null;
            }

            $expiresAt = $session->getAttribute('expires_at');

            if (! $expiresAt instanceof CarbonInterface || $expiresAt->isPast()) {
                throw new AuthenticationFailed;
            }

            $identity = $session->user()->first();

            if (! $identity instanceof UserIdentity) {
                throw new AuthenticationFailed;
            }

            $newRefreshToken = Str::random(96);
            $tokenFamilyId = (string) $session->getRawOriginal('token_family_id');
            $replacement = AuthSession::create([
                'user_id' => $identity->id,
                'token_family_id' => $tokenFamilyId,
                'refresh_token_hash' => hash('sha256', $newRefreshToken),
                'expires_at' => now()->addSeconds((int) config('auth.jwt.refresh_ttl', 1209600)),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            $replacement->forceFill(['token_family_id' => $tokenFamilyId])->save();

            $session->update([
                'last_used_at' => now(),
                'revoked_at' => now(),
                'revoked_reason' => 'rotated',
                'replaced_by_id' => $replacement->id,
            ]);

            return new AuthenticationResult($this->jwt->issue($identity, $replacement), $newRefreshToken, $replacement);
        });

        if ($reuseDetected || ! $result instanceof AuthenticationResult) {
            throw new AuthenticationFailed;
        }

        return $result;
    }

    public function revoke(AuthSession $session, string $reason = 'logout'): void
    {
        $session->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function revokeAll(UserIdentity $identity): void
    {
        $identity->authSessions()->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'revoked_reason' => 'logout_all',
        ]);
    }

    private function findIdentity(string $login): ?UserIdentity
    {
        foreach ([ContactType::Email, ContactType::Phone] as $type) {
            try {
                $canonical = $this->canonicalizer->canonicalize($type, $login);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $identity = UserIdentity::query()
                ->whereHas('contacts', function ($query) use ($type, $canonical): void {
                    $query->where('type', $type->value)->where('canonical_value', $canonical)->whereNull('superseded_at');
                })
                ->first();

            if ($identity instanceof UserIdentity) {
                return $identity;
            }
        }

        return null;
    }
}
