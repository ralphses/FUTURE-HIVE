<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Support\Collection;

final class ListSchoolRolesAction
{
    public function __construct(private readonly ResolveSchoolPermissionsAction $permissions) {}

    /** @return Collection<int, Role> */
    public function handle(UserIdentity $identity, string $schoolPublicId): Collection
    {
        $this->permissions->handle($identity, $schoolPublicId);

        return Role::query()
            ->where('scope', 'school')
            ->where('is_active', true)
            ->with(['permissions' => fn ($query) => $query->where('permissions.is_active', true)->orderBy('key')])
            ->orderBy('key')
            ->get();
    }
}
