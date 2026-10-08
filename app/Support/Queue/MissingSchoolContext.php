<?php

declare(strict_types=1);

namespace App\Support\Queue;

use LogicException;

final class MissingSchoolContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('A school-owned queued job requires an explicit school_id context.');
    }
}
