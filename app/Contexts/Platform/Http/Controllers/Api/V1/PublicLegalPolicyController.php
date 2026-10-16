<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PublicLegalPolicyAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicLegalPolicyController
{
    #[Endpoint(operationId: 'v1.public.legal-policies.index', title: 'List published legal policies', description: 'Lists published legal-policy versions. Draft, retired and unpublished documents are unavailable, and no identity or tenant data is exposed.')]
    #[QueryParameter(name: 'page', description: 'One-based page number.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Bounded page size, maximum 100.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'A paginated collection of published legal policies.', type: 'array')]
    public function index(Request $request, PublicLegalPolicyAction $action): JsonResponse
    {
        $perPage = $request->has('per_page') ? ApiResponse::perPage($request->integer('per_page')) : ApiResponse::DEFAULT_PAGE_SIZE;

        return ApiResponse::paginated($action->index($request->integer('page', 1), $perPage));
    }

    #[Endpoint(operationId: 'v1.public.legal-policies.show', title: 'View a published legal policy', description: 'Returns one published legal-policy version with bounded sections and effective metadata. Retired and unpublished versions are unavailable.')]
    #[Response(status: 200, description: 'A published legal policy.', type: 'array')]
    public function show(string $policy, PublicLegalPolicyAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($policy));
    }
}
