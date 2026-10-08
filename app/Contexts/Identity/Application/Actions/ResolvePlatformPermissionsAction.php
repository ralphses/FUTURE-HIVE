<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Support\Collection;

final class ResolvePlatformPermissionsAction
{
    /** @return Collection<int, Permission> */
    public function handle(UserIdentity $identity): Collection
    {
        return Permission::query()
            ->select('permissions.*')
            ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('platform_role_assignments', 'platform_role_assignments.role_id', '=', 'roles.id')
            ->where('platform_role_assignments.user_id', $identity->id)
            ->whereNull('platform_role_assignments.revoked_at')
            ->where('permissions.scope', 'platform')
            ->where('permissions.is_active', true)
            ->where('roles.scope', 'platform')
            ->where('roles.is_active', true)
            ->distinct()
            ->orderBy('permissions.key')
            ->get();
    }

    public function allows(UserIdentity $identity, string $permissionKey): bool
    {
        return $this->handle($identity)->contains('key', $permissionKey);
    }
}
