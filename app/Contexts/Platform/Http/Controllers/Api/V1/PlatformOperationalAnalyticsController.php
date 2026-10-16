<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PlatformOperationalAnalyticsAction;
use App\Contexts\Platform\Http\Requests\PlatformOperationalAnalyticsRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformOperationalAnalyticsController
{
    #[Endpoint(operationId: 'v1.platform.operational-analytics.overview', title: 'View platform operational analytics overview', description: 'Returns bounded aggregate platform operational indicators from existing school lifecycle, subscription, entitlement, setup and support projections. It is platform-scoped, read-only and excludes student, financial, counselling, contact, credential and audit-detail data.')]
    #[QueryParameter(name: 'period_start', description: 'Inclusive reporting date in YYYY-MM-DD format.', required: false, type: 'string', infer: false, example: '2026-10-01')]
    #[QueryParameter(name: 'period_end', description: 'Inclusive reporting date in YYYY-MM-DD format.', required: false, type: 'string', infer: false, example: '2026-10-15')]
    #[Response(status: 200, description: 'Safe aggregate operational analytics.', type: 'array')]
    public function overview(PlatformOperationalAnalyticsRequest $request, PlatformOperationalAnalyticsAction $action): JsonResponse
    {
        return ApiResponse::data($action->overview($this->identity($request), $request->validated()));
    }

    #[Endpoint(operationId: 'v1.platform.operational-analytics.schools', title: 'List platform operational school summaries', description: 'Lists deterministic safe school-level operational indicators for authorized platform operations. The response contains statuses, bounded counts and issue-safe metadata only; it does not expose school records or sensitive source data.')]
    #[QueryParameter(name: 'status', description: 'School lifecycle filter.', required: false, type: 'string', infer: false, example: 'active')]
    #[QueryParameter(name: 'subscription_state', description: 'Request-time subscription lifecycle filter.', required: false, type: 'string', infer: false, example: 'active')]
    #[QueryParameter(name: 'setup_status', description: 'School setup status filter.', required: false, type: 'string', infer: false, example: 'completed')]
    #[QueryParameter(name: 'period_start', description: 'Inclusive reporting date in YYYY-MM-DD format.', required: false, type: 'string', infer: false, example: '2026-10-01')]
    #[QueryParameter(name: 'period_end', description: 'Inclusive reporting date in YYYY-MM-DD format.', required: false, type: 'string', infer: false, example: '2026-10-15')]
    #[QueryParameter(name: 'page', description: 'One-based page number.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Bounded page size, maximum 100.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'Paginated safe school operational summaries.', type: 'array')]
    public function schools(PlatformOperationalAnalyticsRequest $request, PlatformOperationalAnalyticsAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->schools($this->identity($request), $request->validated()));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
