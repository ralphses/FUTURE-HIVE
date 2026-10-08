<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Cache\SchoolCacheKey;
use App\Support\Queue\MissingSchoolContext;
use App\Support\Queue\RequireSchoolContext;
use App\Support\Queue\RetryableJob;
use App\Support\Queue\SchoolAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class QueueCacheSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_policy_exposes_bounded_attempts_backoff_and_retry_window(): void
    {
        config([
            'queue.policy.tries' => 3,
            'queue.policy.backoff' => [10, 30, 60],
            'queue.policy.retry_window' => 300,
        ]);

        $job = new RetryablePolicyProbeJob('school-fictional-001');

        self::assertSame(3, $job->tries());
        self::assertSame([10, 30, 60], $job->backoff());
        self::assertGreaterThan(now()->addSeconds(299), $job->retryUntil());
    }

    public function test_failing_database_job_is_retried_and_recorded_as_failed(): void
    {
        config([
            'queue.default' => 'database',
            'queue.policy.backoff' => [0, 0, 0],
        ]);

        FailingQueueProbeJob::dispatch('school-fictional-001');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->artisan('queue:work database --once --tries=3 --backoff=0');
        }

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->artisan('queue:failed')->assertExitCode(0);
        $this->artisan('queue:retry', ['id' => 'all'])->assertExitCode(0);

        self::assertArrayHasKey('queue:retry', Artisan::all());
        self::assertArrayHasKey('queue:forget', Artisan::all());
    }

    public function test_school_context_middleware_rejects_missing_context(): void
    {
        $this->expectException(MissingSchoolContext::class);

        (new RequireSchoolContext)->handle(
            new ContextProbeJob(''),
            static function (object $job): void {},
        );
    }

    public function test_school_context_survives_job_serialization_and_is_validated(): void
    {
        $job = unserialize(serialize(new ContextProbeJob('school-fictional-001')));
        self::assertInstanceOf(ContextProbeJob::class, $job);
        self::assertSame('school-fictional-001', $job->schoolId());

        $handled = false;
        (new RequireSchoolContext)->handle(
            $job,
            static function (object $job) use (&$handled): void {
                $handled = true;
            },
        );

        self::assertTrue($handled);
    }

    public function test_school_cache_keys_are_deterministic_and_tenant_distinct(): void
    {
        $first = SchoolCacheKey::make('school-fictional-001', 'dashboard.summary');
        $second = SchoolCacheKey::make('school-fictional-002', 'dashboard.summary');

        self::assertSame($first, SchoolCacheKey::make('school-fictional-001', 'dashboard.summary'));
        self::assertNotSame($first, $second);

        Cache::put($first, 'first-school', 60);
        Cache::put($second, 'second-school', 60);

        self::assertSame('first-school', Cache::get($first));
        self::assertSame('second-school', Cache::get($second));
    }

    public function test_school_cache_keys_require_explicit_school_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SchoolCacheKey::make('', 'dashboard.summary');
    }

    public function test_scheduler_registers_failed_job_pruning(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('queue:prune-failed')
            ->assertExitCode(0);
    }
}

final class RetryablePolicyProbeJob implements SchoolAwareJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetryableJob;
    use SerializesModels;

    public function __construct(private readonly string $schoolContext) {}

    public function schoolId(): string
    {
        return $this->schoolContext;
    }

    public function handle(): void {}
}

final class FailingQueueProbeJob implements SchoolAwareJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [0, 0, 0];

    public function __construct(private readonly string $schoolContext) {}

    public function schoolId(): string
    {
        return $this->schoolContext;
    }

    /**
     * @return array<int, RequireSchoolContext>
     */
    public function middleware(): array
    {
        return [new RequireSchoolContext];
    }

    public function handle(): void
    {
        throw new RuntimeException('fictional queue probe failure');
    }
}

final class ContextProbeJob implements SchoolAwareJob
{
    public function __construct(private readonly string $schoolContext) {}

    public function schoolId(): string
    {
        return $this->schoolContext;
    }
}
