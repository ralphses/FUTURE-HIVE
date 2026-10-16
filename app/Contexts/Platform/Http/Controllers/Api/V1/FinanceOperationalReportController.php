<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Http\Requests\OperationalReportingRequest;
use App\Support\Http\ApiResponse;
use App\Support\OperationalReportingAction;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class FinanceOperationalReportController
{
    #[Endpoint(title: 'View finance operational report', description: 'Returns bounded invoice, allocation, currency and reconciliation indicators without financial amounts or private records.')]
    #[Response(status: 200, description: 'Safe finance report projection.', type: 'array')]
    public function __invoke(OperationalReportingRequest $request, string $school, OperationalReportingAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless(is_a($identity, 'App\\Contexts\\Identity\\Domain\\Models\\UserIdentity'), 401);

        return ApiResponse::data($action->execute($identity, $school, 'finance', $request->validated()));
    }
}
