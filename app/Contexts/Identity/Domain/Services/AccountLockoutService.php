<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Services;

use App\Contexts\Identity\Application\Actions\RecordSecurityEventAction;
use App\Contexts\Identity\Domain\Models\IdentitySecurityState;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Support\Facades\DB;

final class AccountLockoutService
{
    public function __construct(private readonly RecordSecurityEventAction $events) {}

    public function assertLoginAllowed(UserIdentity $identity, ?string $ipAddress, ?string $userAgent): void
    {
        $state = $identity->securityState;

        if ($state?->locked_until !== null && $state->locked_until->isFuture()) {
            $this->events->execute('login.locked', 'denied', $identity, null, $ipAddress, $userAgent);
            throw new AuthenticationFailed;
        }
    }

    public function recordFailure(
        ?UserIdentity $identity,
        string $login,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        DB::transaction(function () use ($identity, $login, $ipAddress, $userAgent): void {
            $this->events->execute('login.failed', 'denied', $identity, $login, $ipAddress, $userAgent);

            if (! $identity instanceof UserIdentity) {
                return;
            }

            $state = IdentitySecurityState::query()->where('user_id', $identity->id)->lockForUpdate()->first();
            if (! $state instanceof IdentitySecurityState) {
                $state = IdentitySecurityState::query()->create(['user_id' => $identity->id]);
            }

            $now = now();
            $windowStarted = $state->failure_window_started_at;
            if ($windowStarted === null || $windowStarted->addMinutes((int) config('auth.lockout.failure_window_minutes', 15))->isPast()) {
                $state->failed_login_attempts = 0;
                $state->failure_window_started_at = $now;
            }

            $state->failed_login_attempts++;
            $state->last_failed_login_at = $now;

            if ($state->failed_login_attempts >= (int) config('auth.lockout.threshold', 5)) {
                $state->lockout_level = min($state->lockout_level + 1, 3);
                $durations = config('auth.lockout.durations_minutes', [15, 60, 1440]);
                $duration = (int) ($durations[$state->lockout_level - 1] ?? 1440);
                $state->locked_until = $now->copy()->addMinutes($duration);
                $state->failed_login_attempts = 0;
                $this->events->execute('account.locked', 'denied', $identity, null, $ipAddress, $userAgent, [
                    'lockout_level' => $state->lockout_level,
                    'duration_minutes' => $duration,
                ]);
            }

            $state->save();
        });
    }

    public function recordSuccess(UserIdentity $identity): void
    {
        $state = IdentitySecurityState::query()->where('user_id', $identity->id)->first();

        if ($state instanceof IdentitySecurityState) {
            $state->update([
                'failed_login_attempts' => 0,
                'failure_window_started_at' => null,
                'lockout_level' => 0,
                'locked_until' => null,
                'last_successful_login_at' => now(),
            ]);
        } else {
            IdentitySecurityState::query()->create(['user_id' => $identity->id, 'last_successful_login_at' => now()]);
        }
    }

    public function clear(UserIdentity $identity): void
    {
        IdentitySecurityState::query()->where('user_id', $identity->id)->update([
            'failed_login_attempts' => 0,
            'failure_window_started_at' => null,
            'lockout_level' => 0,
            'locked_until' => null,
        ]);
    }
}
