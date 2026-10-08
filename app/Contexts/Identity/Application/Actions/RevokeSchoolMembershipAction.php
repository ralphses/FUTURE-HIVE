<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevokeSchoolMembershipAction
{
    public function handle(UserIdentity $actor, string $membershipPublicId, string $reason = 'owner_revoked'): void
    {
        DB::transaction(function () use ($actor, $membershipPublicId, $reason): void {
            $membership = SchoolMembership::query()
                ->where('public_id', $membershipPublicId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $membership instanceof SchoolMembership || ! SchoolMembership::query()
                ->where('school_id', $membership->school_id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->where('is_owner', true)
                ->exists()) {
                $this->notFound();
            }

            if ($membership->is_owner) {
                throw ValidationException::withMessages([
                    'membership' => 'The owner membership cannot be revoked in this slice.',
                ]);
            }

            $membership->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
    }
}
