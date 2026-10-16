<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface PlatformPermissionResolver
{
    public function allows(Authenticatable $actor, string $permission): bool;
}
