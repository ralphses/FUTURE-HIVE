<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PlatformSupportAction;
use App\Contexts\Platform\Http\Requests\PlatformSupportAccessRequest;
use App\Contexts\Platform\Http\Requests\PlatformSupportCaseRequest;
use App\Contexts\Platform\Http\Requests\PlatformSupportCaseTransitionRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformSupportController
{
    #[Endpoint(operationId: 'v1.platform.support-cases.index', title: 'List platform support cases', description: 'Lists bounded support-case metadata for authorized platform operations. School data, credentials, tokens and raw audit metadata are excluded.')]
    #[Response(status: 200, description: 'A paginated safe support-case projection.', type: 'array')]
    public function index(Request $request, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->cases($this->identity($request), $request->only(['status', 'school', 'per_page'])));
    }

    #[Endpoint(operationId: 'v1.platform.support-cases.store', title: 'Create a platform support case', description: 'Creates a support case linked to an active school. The server controls ownership and lifecycle.')]
    public function store(PlatformSupportCaseRequest $request, string $school, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::data($action->createCase($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(operationId: 'v1.platform.support-cases.show', title: 'View a platform support case', description: 'Returns safe case metadata and no unrestricted school records.')]
    public function show(Request $request, string $case, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::data($action->showCase($this->identity($request), $case));
    }

    #[Endpoint(operationId: 'v1.platform.support-cases.status', title: 'Change platform support case status', description: 'Transitions a support case through the server-controlled lifecycle and retains history.')]
    public function status(PlatformSupportCaseTransitionRequest $request, string $case, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionCase($this->identity($request), $case, $request->validated('status')));
    }

    #[Endpoint(operationId: 'v1.platform.support-access.index', title: 'List support access grants', description: 'Lists bounded access-grant metadata for one support case. Credentials and raw tokens are never returned.')]
    public function grants(Request $request, string $case, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::data($action->grants($this->identity($request), $case));
    }

    #[Endpoint(operationId: 'v1.platform.support-access.store', title: 'Request support access', description: 'Requests least-privilege, time-limited support access for an open case using an idempotency key.')]
    public function requestAccess(PlatformSupportAccessRequest $request, string $case, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::data($action->requestGrant($this->identity($request), $case, $request->validated()), 201);
    }

    #[Endpoint(operationId: 'v1.platform.support-access.approve', title: 'Approve support access', description: 'Approves a bounded support-access request with maker-checker separation.')]
    public function approve(Request $request, string $grant, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::data($action->approveGrant($this->identity($request), $grant));
    }

    #[Endpoint(operationId: 'v1.platform.support-access.revoke', title: 'Revoke support access', description: 'Immediately revokes an approved support-access grant while retaining its history.')]
    public function revoke(Request $request, string $grant, PlatformSupportAction $action): JsonResponse
    {
        return ApiResponse::data($action->revokeGrant($this->identity($request), $grant));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
