<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolInvitation;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AcceptSchoolInvitationAction
{
    public function handle(UserIdentity $identity, string $invitationPublicId, string $token): SchoolMembership
    {
        $expired = false;
        $membership = DB::transaction(function () use ($identity, $invitationPublicId, $token, &$expired): ?SchoolMembership {
            $invitation = SchoolInvitation::query()
                ->where('public_id', $invitationPublicId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof SchoolInvitation) {
                $this->notFound();
            }

            $expiresAt = $invitation->getAttribute('expires_at');

            if (! $expiresAt instanceof Carbon || $expiresAt->isPast()) {
                $invitation->update([
                    'status' => 'expired',
                    'revoked_reason' => 'expired',
                ]);
                $expired = true;

                return null;
            }

            if ($invitation->invitee_id !== $identity->id
                || ! hash_equals((string) $invitation->getRawOriginal('token_hash'), hash('sha256', $token))) {
                $this->notFound();
            }

            $membership = SchoolMembership::query()
                ->where('school_id', $invitation->school_id)
                ->where('user_id', $identity->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $membership instanceof SchoolMembership) {
                $membership = SchoolMembership::create([
                    'school_id' => $invitation->school_id,
                    'user_id' => $identity->id,
                    'status' => 'active',
                    'is_owner' => false,
                    'joined_at' => now(),
                ]);
            }

            $invitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            return $membership;
        });

        if ($expired || ! $membership instanceof SchoolMembership) {
            $this->notFound();
        }

        return $membership;
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(SchoolInvitation::class);
    }
}
