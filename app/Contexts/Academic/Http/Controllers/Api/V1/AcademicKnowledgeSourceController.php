<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicKnowledgeSourceAction;
use App\Contexts\Academic\Http\Requests\StoreAcademicKnowledgeSourceRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicKnowledgeSourceRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicKnowledgeSourceController
{
    #[Endpoint(operationId: 'v1.platform.academic-knowledge-sources.index', title: 'List academic knowledge sources', description: 'Lists safe versioned JAMB and institution requirement sources for approved platform governance. Unpublished source content is not exposed to student users.')]
    #[Response(status: 200, description: 'Paginated academic knowledge sources.', type: 'array')]
    public function index(Request $request, AcademicKnowledgeSourceAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $request->integer('page', 1), min(100, max(1, $request->integer('per_page', 25)))));
    }

    #[Endpoint(operationId: 'v1.platform.academic-knowledge-sources.store', title: 'Create an academic knowledge source', description: 'Creates a draft, bounded and versioned JAMB or institution requirement source. Source ownership and approval remain platform-controlled.')]
    #[Response(status: 201, description: 'Created academic knowledge source.', type: 'array')]
    public function store(StoreAcademicKnowledgeSourceRequest $request, AcademicKnowledgeSourceAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $request->validated()), 201);
    }

    #[Endpoint(operationId: 'v1.platform.academic-knowledge-sources.show', title: 'View an academic knowledge source', description: 'Returns a safe source version projection with bounded requirement content and no provider payloads or private metadata.')]
    #[Response(status: 200, description: 'Academic knowledge source.', type: 'array')]
    public function show(Request $request, string $source, AcademicKnowledgeSourceAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $source));
    }

    #[Endpoint(operationId: 'v1.platform.academic-knowledge-sources.update', title: 'Update a draft academic knowledge source', description: 'Updates only a draft source version. Published and retired versions are immutable and require a new version.')]
    #[Response(status: 200, description: 'Updated academic knowledge source.', type: 'array')]
    public function update(UpdateAcademicKnowledgeSourceRequest $request, string $source, AcademicKnowledgeSourceAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $source, $request->validated()));
    }

    #[Endpoint(operationId: 'v1.platform.academic-knowledge-sources.lifecycle', title: 'Change academic knowledge source lifecycle', description: 'Publishes, retires or rejects a source version with overlap protection and sanitized audit history.')]
    #[Response(status: 200, description: 'Updated academic knowledge source lifecycle.', type: 'array')]
    public function lifecycle(Request $request, string $source, string $transition, AcademicKnowledgeSourceAction $action): JsonResponse
    {
        abort_unless(in_array($transition, ['publish', 'retire', 'reject'], true), 404);

        return ApiResponse::data($action->lifecycle($this->identity($request), $source, $transition));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
