<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ReadinessCheckAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequestId;
use App\Support\Http\ApiResponse;
use App\Support\Observability\MetricsRecorder;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadinessController extends Controller
{
    public function __construct(
        private readonly ReadinessCheckAction $readinessCheckAction,
        private readonly MetricsRecorder $metrics,
    ) {}

    #[Endpoint(
        title: 'Readiness check',
        description: 'Checks safe application dependencies without contacting external file, notification or payment providers.',
    )]
    #[Header(
        name: 'X-Request-ID',
        description: 'Request correlation identifier.',
        type: 'string',
        format: 'uuid',
        required: true,
        status: '*',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->readinessCheckAction->execute();
        $this->metrics->increment('health.readiness.checked', [
            'status' => $result['status'],
        ]);

        if (! $result['ready']) {
            return ApiResponse::error(
                'SERVICE_UNAVAILABLE',
                'The service is not ready.',
                ['checks' => $result['checks']],
                503,
                (string) $request->attributes->get(RequestId::ATTRIBUTE),
            );
        }

        return ApiResponse::data([
            'status' => $result['status'],
            'checks' => $result['checks'],
        ]);
    }
}
