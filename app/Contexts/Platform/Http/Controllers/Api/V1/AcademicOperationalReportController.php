<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Http\Requests\OperationalReportingRequest;
use App\Support\Http\ApiResponse;
use App\Support\OperationalReportingAction;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class AcademicOperationalReportController
{
    #[Endpoint(title: 'View academic operational report', description: 'Returns bounded assessment and result-completion indicators without raw scores or student results.')]
    #[Response(status: 200, description: 'Safe academic report projection.', type: 'array')]
    public function __invoke(OperationalReportingRequest $request, string $school, OperationalReportingAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless(is_a($identity, 'App\\Contexts\\Identity\\Domain\\Models\\UserIdentity'), 401);

        return ApiResponse::data($action->execute($identity, $school, 'academic', $request->validated()));
    }
}
