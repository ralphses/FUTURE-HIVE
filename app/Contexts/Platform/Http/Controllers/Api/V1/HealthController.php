<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\HealthCheckAction;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __construct(
        private readonly HealthCheckAction $healthCheckAction,
    ) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::data($this->healthCheckAction->execute());
    }
}
