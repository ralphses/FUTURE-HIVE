<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\AuthenticationFailed;
use App\Contexts\Identity\Domain\Services\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ChangePasswordAction
{
    public function __construct(private readonly PasswordPolicy $passwordPolicy) {}

    public function change(UserIdentity $identity, AuthSession $currentSession, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $identity->getAuthPassword())) {
            throw new AuthenticationFailed;
        }

        $this->passwordPolicy->validate($newPassword);

        DB::transaction(function () use ($identity, $currentSession, $newPassword): void {
            $identity->password = $newPassword;
            $identity->save();
            $identity->authSessions()
                ->whereNull('revoked_at')
                ->whereKeyNot($currentSession->id)
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'password_changed',
                ]);
        });
    }
}
