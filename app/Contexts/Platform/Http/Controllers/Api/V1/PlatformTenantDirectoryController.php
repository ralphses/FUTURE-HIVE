<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PlatformTenantDirectoryAction;
use App\Contexts\Platform\Http\Requests\PlatformTenantDirectoryRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformTenantDirectoryController
{
    #[Endpoint(operationId: 'v1.platform.schools.index', title: 'List platform schools', description: 'Lists safe platform tenant metadata for authorized platform operations. This platform-scoped read does not use school membership context and excludes student, financial, contact, credential and private source data.')]
    #[QueryParameter(name: 'status', description: 'School lifecycle filter.', required: false, type: 'string', infer: false, example: 'active')]
    #[QueryParameter(name: 'subscription_state', description: 'Request-time subscription lifecycle filter.', required: false, type: 'string', infer: false, example: 'active')]
    #[QueryParameter(name: 'setup_status', description: 'School setup readiness filter.', required: false, type: 'string', infer: false, example: 'completed')]
    #[QueryParameter(name: 'page', description: 'One-based page number.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Bounded page size, maximum 100.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'A paginated safe platform school directory.', type: 'array')]
    public function index(PlatformTenantDirectoryRequest $request, PlatformTenantDirectoryAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $request->validated()));
    }

    #[Endpoint(operationId: 'v1.platform.schools.show', title: 'View platform school metadata', description: 'Returns one safe platform tenant projection with lifecycle, academic-context, setup, subscription and entitlement status. It never exposes school records or sensitive source data.')]
    #[Response(status: 200, description: 'A safe platform school projection.', type: 'array')]
    public function show(Request $request, string $school, PlatformTenantDirectoryAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school));
    }

    #[Endpoint(operationId: 'v1.platform.schools.entitlements', title: 'View platform school entitlements', description: 'Returns the effective safe entitlement projection for one school. It excludes pricing, payment, provider data and internal identifiers.')]
    #[Response(status: 200, description: 'A safe effective entitlement projection.', type: 'array')]
    public function entitlements(Request $request, string $school, PlatformTenantDirectoryAction $action): JsonResponse
    {
        return ApiResponse::data($action->entitlements($this->identity($request), $school));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
