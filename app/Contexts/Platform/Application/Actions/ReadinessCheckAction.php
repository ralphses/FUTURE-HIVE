<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use Illuminate\Support\Facades\DB;

final class ReadinessCheckAction
{
    /**
     * @return array{ready: bool, status: string, checks: array<string, string>}
     */
    public function execute(): array
    {
        $checks = [
            'application' => filled(config('app.key')) ? 'ok' : 'unavailable',
            'database' => $this->databaseCheck(),
            'cache' => filled(config('cache.default')) ? 'configured' : 'unavailable',
            'queue' => filled(config('queue.default')) ? 'configured' : 'unavailable',
            'storage' => filled(config('services.cloudinary.url')) ? 'configured' : 'unavailable',
        ];

        $ready = ! in_array('unavailable', $checks, true);

        return [
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
        ];
    }

    private function databaseCheck(): string
    {
        try {
            DB::connection()->getPdo();

            return 'ok';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
