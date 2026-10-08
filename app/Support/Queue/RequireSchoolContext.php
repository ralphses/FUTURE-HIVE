<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Closure;

final class RequireSchoolContext
{
    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        if (! $job instanceof SchoolAwareJob || trim($job->schoolId()) === '') {
            throw new MissingSchoolContext;
        }

        $next($job);
    }
}
