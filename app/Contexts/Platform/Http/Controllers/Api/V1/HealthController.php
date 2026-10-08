<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\HealthCheckAction;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __construct(
        private readonly HealthCheckAction $healthCheckAction,
    ) {}

    #[Endpoint(
        title: 'Liveness check',
        description: 'Returns a process-level liveness response without checking external dependencies.',
    )]
    #[Header(
        name: 'X-Request-ID',
        description: 'Request correlation identifier.',
        type: 'string',
        format: 'uuid',
        required: true,
        status: 200,
    )]
    public function __invoke(): JsonResponse
    {
        return ApiResponse::data($this->healthCheckAction->execute());
    }
}
