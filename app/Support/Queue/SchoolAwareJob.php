<?php

declare(strict_types=1);

namespace App\Support\Queue;

interface SchoolAwareJob
{
    public function schoolId(): string;
}
