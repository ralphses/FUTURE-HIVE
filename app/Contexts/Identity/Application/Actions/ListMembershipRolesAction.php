<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class ListMembershipRolesAction
{
    public function __construct(private readonly ResolveSchoolPermissionsAction $permissions) {}

    /** @return Collection<int, MembershipRole> */
    public function handle(UserIdentity $identity, string $schoolPublicId, string $membershipPublicId): Collection
    {
        $this->permissions->handle($identity, $schoolPublicId);
        $membership = SchoolMembership::query()
            ->where('public_id', $membershipPublicId)
            ->where('status', 'active')
            ->whereHas('school', fn ($query) => $query->where('public_id', $schoolPublicId))
            ->first();

        if ($membership === null) {
            throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
        }

        return MembershipRole::query()->where('school_membership_id', $membership->id)->whereNull('revoked_at')->with('role')->get();
    }
}
