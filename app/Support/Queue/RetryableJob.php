<?php

declare(strict_types=1);

namespace App\Support\Queue;

use DateTimeInterface;

trait RetryableJob
{
    public function tries(): int
    {
        return (int) config('queue.policy.tries', 3);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_values(array_map(
            static fn (mixed $seconds): int => (int) $seconds,
            (array) config('queue.policy.backoff', [10, 30, 60]),
        ));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds((int) config('queue.policy.retry_window', 300));
    }
}
