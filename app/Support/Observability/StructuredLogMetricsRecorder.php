<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Log\LogManager;

final class StructuredLogMetricsRecorder implements MetricsRecorder
{
    public function __construct(
        private readonly LogManager $logs,
    ) {}

    public function increment(string $name, array $tags = []): void
    {
        $this->logs->channel('structured')->info('metric.increment', [
            'metric' => $name,
            'tags' => $tags,
        ]);
    }

    public function timing(string $name, int $milliseconds, array $tags = []): void
    {
        $this->logs->channel('structured')->info('metric.timing', [
            'metric' => $name,
            'milliseconds' => $milliseconds,
            'tags' => $tags,
        ]);
    }
}
