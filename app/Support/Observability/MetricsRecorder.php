<?php

declare(strict_types=1);

namespace App\Support\Observability;

interface MetricsRecorder
{
    /**
     * @param  array<string, scalar>  $tags
     */
    public function increment(string $name, array $tags = []): void;

    /**
     * @param  array<string, scalar>  $tags
     */
    public function timing(string $name, int $milliseconds, array $tags = []): void;
}
