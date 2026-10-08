<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

final class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A trusted tenant context is required for this operation.');
    }
}
