<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Contexts\Identity\Domain\Models\School;
use App\Support\Tenancy\TenantContext;
use Closure;

final class RequireSchoolContext
{
    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        if (! $job instanceof SchoolAwareJob) {
            throw new MissingSchoolContext;
        }

        $jobContext = $job->tenantContext();
        $school = School::query()
            ->whereKey($jobContext->schoolId)
            ->where('public_id', $jobContext->schoolPublicId)
            ->where('status', 'active')
            ->first();

        if (! $school instanceof School || $job->schoolId() !== $jobContext->schoolPublicId) {
            throw new MissingSchoolContext;
        }

        TenantContext::runInternal(
            TenantContext::forSchool((int) $school->id, (string) $school->public_id),
            'queued school job execution',
            static function () use ($job, $next): null {
                $next($job);

                return null;
            },
        );
    }
}
