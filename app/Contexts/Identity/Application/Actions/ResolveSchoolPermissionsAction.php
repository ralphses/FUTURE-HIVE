<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class ResolveSchoolPermissionsAction
{
    /** @return Collection<int, Permission> */
    public function handle(UserIdentity $identity, string $schoolPublicId): Collection
    {
        $membership = SchoolMembership::query()
            ->where('user_id', $identity->id)
            ->where('status', 'active')
            ->whereHas('school', fn ($query) => $query->where('public_id', $schoolPublicId)->where('status', 'active'))
            ->first();

        if (! $membership instanceof SchoolMembership) {
            throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
        }

        return Permission::query()
            ->select('permissions.*')
            ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('membership_roles', 'membership_roles.role_id', '=', 'roles.id')
            ->where('membership_roles.school_membership_id', $membership->id)
            ->whereNull('membership_roles.revoked_at')
            ->where('permissions.is_active', true)
            ->where('roles.is_active', true)
            ->distinct()
            ->orderBy('permissions.key')
            ->get();
    }

    public function allows(UserIdentity $identity, string $schoolPublicId, string $permissionKey): bool
    {
        return $this->handle($identity, $schoolPublicId)->contains('key', $permissionKey);
    }

    public function allowsLifecycle(UserIdentity $identity, string $schoolPublicId, string $permissionKey): bool
    {
        $membership = SchoolMembership::query()
            ->where('user_id', $identity->id)
            ->where('status', 'active')
            ->whereHas('school', fn ($query) => $query->where('public_id', $schoolPublicId)->whereIn('status', ['active', 'suspended']))
            ->first();

        if (! $membership instanceof SchoolMembership) {
            return false;
        }

        return Permission::query()
            ->select('permissions.*')
            ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('membership_roles', 'membership_roles.role_id', '=', 'roles.id')
            ->where('membership_roles.school_membership_id', $membership->id)
            ->whereNull('membership_roles.revoked_at')
            ->where('permissions.is_active', true)
            ->where('roles.is_active', true)
            ->where('permissions.key', $permissionKey)
            ->exists();
    }
}
