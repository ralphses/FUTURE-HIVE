<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\SchoolInvitation;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RevokeSchoolInvitationAction
{
    public function __construct(private readonly ResolveSchoolPermissionsAction $permissions) {}

    public function handle(UserIdentity $actor, string $invitationPublicId): void
    {
        DB::transaction(function () use ($actor, $invitationPublicId): void {
            $invitation = SchoolInvitation::query()
                ->where('public_id', $invitationPublicId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof SchoolInvitation || ! $this->permissions->allows($actor, $invitation->school->public_id, 'school.memberships.invite')) {
                $this->notFound();
            }

            $invitation->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_reason' => 'owner_revoked',
            ]);
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(SchoolInvitation::class);
    }
}
