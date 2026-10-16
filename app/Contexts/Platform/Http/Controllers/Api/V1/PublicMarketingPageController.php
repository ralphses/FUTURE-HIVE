<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PublicMarketingPageAction;
use App\Contexts\Platform\Http\Requests\PublicMarketingPageRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicMarketingPageController
{
    #[Endpoint(operationId: 'v1.public.marketing-pages.index', title: 'List published marketing pages', description: 'Lists approved public SchoolOS marketing content. Only published benefits, FAQs and product copy are returned; no tenant, contact or invented metric data is exposed.')]
    #[QueryParameter(name: 'category', description: 'Optional content category.', required: false, type: 'string', infer: false, example: 'benefits')]
    #[QueryParameter(name: 'page', description: 'One-based page number.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Bounded page size, maximum 100.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'A paginated collection of published marketing pages.', type: 'array')]
    public function index(PublicMarketingPageRequest $request, PublicMarketingPageAction $action): JsonResponse
    {
        $perPage = $request->has('per_page') ? $request->integer('per_page') : ApiResponse::DEFAULT_PAGE_SIZE;

        return ApiResponse::paginated($action->index($request->integer('page', 1), ApiResponse::perPage($perPage), $request->string('category')->toString() ?: null));
    }

    #[Endpoint(operationId: 'v1.public.marketing-pages.show', title: 'View a published marketing page', description: 'Returns one approved public marketing page with bounded sections and version metadata. Draft, retired and unpublished content is unavailable.')]
    #[Response(status: 200, description: 'A published marketing page.', type: 'array')]
    public function show(Request $request, string $page, PublicMarketingPageAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($page));
    }
}
