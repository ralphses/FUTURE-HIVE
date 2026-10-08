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
    public function __construct(private readonly ResolveSchoolPermissionsAction $permissions) {}

    public function handle(UserIdentity $actor, string $membershipPublicId, string $reason = 'owner_revoked'): void
    {
        DB::transaction(function () use ($actor, $membershipPublicId, $reason): void {
            $membership = SchoolMembership::query()
                ->where('public_id', $membershipPublicId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $membership instanceof SchoolMembership || ! $this->permissions->allows($actor, $membership->school->public_id, 'school.memberships.revoke')) {
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
            $membership->identity()->first()?->authSessions()->where('active_school_membership_id', $membership->id)->update([
                'active_school_membership_id' => null,
            ]);
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
    }
}
