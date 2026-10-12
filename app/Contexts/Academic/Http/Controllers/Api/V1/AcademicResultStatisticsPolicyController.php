<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultStatisticsPolicyAction;
use App\Contexts\Academic\Http\Requests\UpsertAcademicResultStatisticsPolicyRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicResultStatisticsPolicyController
{
    #[Endpoint(title: 'List ranking and statistics policies', description: 'Lists immutable school-scoped ranking and statistics policy versions for a term. This configuration endpoint does not calculate rankings or expose leaderboards.')]
    #[Response(status: 200, description: 'Ranking and statistics policies.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, AcademicResultStatisticsPolicyAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'Create a ranking and statistics policy', description: 'Creates a draft versioned policy over published result snapshots. School ownership, version and lifecycle metadata are server-controlled; no ranking is computed.')]
    #[Response(status: 201, description: 'Created ranking and statistics policy.', type: 'array')]
    public function store(UpsertAcademicResultStatisticsPolicyRequest $request, string $school, string $session, string $term, AcademicResultStatisticsPolicyAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $request->validated()), 201);
    }

    #[Endpoint(title: 'View a ranking and statistics policy', description: 'Shows one immutable policy version and its safe configuration values.')]
    #[Response(status: 200, description: 'Ranking and statistics policy.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $policy, AcademicResultStatisticsPolicyAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $policy));
    }

    #[Endpoint(title: 'Activate a ranking and statistics policy', description: 'Activates one draft policy version for a school/session/term. Activation does not calculate rankings or change published results.')]
    #[Response(status: 200, description: 'Activated policy.', type: 'array')]
    public function activate(Request $request, string $school, string $session, string $term, string $policy, AcademicResultStatisticsPolicyAction $action): JsonResponse
    {
        return ApiResponse::data($action->activate($this->identity($request), $school, $session, $term, $policy));
    }

    #[Endpoint(title: 'Retire a ranking and statistics policy', description: 'Retires an active policy version while retaining its historical configuration.')]
    #[Response(status: 200, description: 'Retired policy.', type: 'array')]
    public function retire(Request $request, string $school, string $session, string $term, string $policy, AcademicResultStatisticsPolicyAction $action): JsonResponse
    {
        return ApiResponse::data($action->retire($this->identity($request), $school, $session, $term, $policy));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
