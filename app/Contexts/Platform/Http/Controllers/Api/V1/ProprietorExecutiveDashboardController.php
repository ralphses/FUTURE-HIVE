<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ProprietorExecutiveDashboardAction;
use App\Contexts\Platform\Http\Requests\PrincipalReviewDashboardRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class ProprietorExecutiveDashboardController
{
    #[Endpoint(title: 'View proprietor executive dashboard', description: 'Returns a read-only school-scoped executive dashboard of safe operational, academic, finance and setup indicators for proprietors. It never exposes raw source values or mutates source records.')]
    #[Response(status: 200, description: 'Safe proprietor executive dashboard projection.', type: 'array')]
    public function __invoke(PrincipalReviewDashboardRequest $request, string $school, ProprietorExecutiveDashboardAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless(is_a($identity, 'App\\Contexts\\Identity\\Domain\\Models\\UserIdentity'), 401);

        /** @var array{session?: string|null, term?: string|null} $filters */
        $filters = $request->validated();

        return ApiResponse::data($action->execute($identity, $school, $filters));
    }
}
