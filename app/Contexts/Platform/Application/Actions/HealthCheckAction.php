<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

final class HealthCheckAction
{
    /**
     * @return array{status: string}
     */
    public function execute(): array
    {
        return [
            'status' => 'ok',
        ];
    }
}
