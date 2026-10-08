<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Contexts\Identity\Application\Actions\ResolveSchoolPermissionsAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Contracts\Auth\Authenticatable;

final class SchoolPermissionChecker
{
    public function __construct(private readonly ResolveSchoolPermissionsAction $permissions) {}

    public function allows(Authenticatable $actor, string $schoolPublicId, string $permission): bool
    {
        return $actor instanceof UserIdentity
            && $this->permissions->allows($actor, $schoolPublicId, $permission);
    }

    public function allowsLifecycle(Authenticatable $actor, string $schoolPublicId, string $permission): bool
    {
        return $actor instanceof UserIdentity
            && $this->permissions->allowsLifecycle($actor, $schoolPublicId, $permission);
    }
}
