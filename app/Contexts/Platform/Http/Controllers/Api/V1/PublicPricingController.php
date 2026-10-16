<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PublicPricingAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicPricingController
{
    #[Endpoint(operationId: 'v1.public.pricing.index', title: 'List public pricing plans', description: 'Lists published and approved public plan labels, entitlement summaries and availability. Prices are unavailable unless an approved pricing source exists; no school subscription or billing data is exposed.')]
    #[QueryParameter(name: 'page', description: 'One-based page number.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Bounded page size, maximum 100.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'A paginated collection of public pricing plans.', type: 'array')]
    public function index(Request $request, PublicPricingAction $action): JsonResponse
    {
        $perPage = $request->has('per_page') ? ApiResponse::perPage($request->integer('per_page')) : ApiResponse::DEFAULT_PAGE_SIZE;

        return ApiResponse::paginated($action->index($request->integer('page', 1), $perPage));
    }

    #[Endpoint(operationId: 'v1.public.pricing.show', title: 'View a public pricing plan', description: 'Returns one published and approved public plan with safe entitlement and availability metadata. Pricing remains unavailable unless an approved pricing source exists.')]
    #[Response(status: 200, description: 'A public pricing plan.', type: 'array')]
    public function show(string $plan, PublicPricingAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($plan));
    }
}
