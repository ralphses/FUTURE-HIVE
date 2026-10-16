<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PrincipalReviewDashboardAction;
use App\Contexts\Platform\Http\Requests\PrincipalReviewDashboardRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class PrincipalReviewDashboardController
{
    #[Endpoint(title: 'View principal review dashboard', description: 'Returns a read-only school-scoped dashboard of safe academic, attendance, finance and import review indicators. It never exposes raw source values or mutates source records.')]
    #[Response(status: 200, description: 'Safe principal review dashboard projection.', type: 'array')]
    public function __invoke(PrincipalReviewDashboardRequest $request, string $school, PrincipalReviewDashboardAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless(is_a($identity, 'App\\Contexts\\Identity\\Domain\\Models\\UserIdentity'), 401);

        /** @var array{session?: string|null, term?: string|null} $filters */
        $filters = $request->validated();

        return ApiResponse::data($action->execute($identity, $school, $filters));
    }
}
