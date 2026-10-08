<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolInvitation;

final class ExpireSchoolInvitationsAction
{
    public function handle(): int
    {
        return SchoolInvitation::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'revoked_reason' => 'expired',
            ]);
    }
}
