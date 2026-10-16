<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\AiEvaluationAction;
use App\Contexts\Platform\Http\Requests\ReviewAiEvaluationRequest;
use App\Contexts\Platform\Http\Requests\StoreAiEvaluationRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiEvaluationController
{
    #[Endpoint(operationId: 'v1.platform.ai-evaluations.index', title: 'List AI evaluations', description: 'Lists safe evaluation runs for approved AI features. Responses contain aggregate rubric results only and never raw fixtures, student data or provider payloads.')]
    #[Response(status: 200, description: 'Paginated AI evaluation runs.', type: 'array')]
    public function index(Request $request, AiEvaluationAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $request->only(['feature_key', 'state', 'per_page'])));
    }

    #[Endpoint(operationId: 'v1.platform.ai-evaluations.store', title: 'Create an AI evaluation', description: 'Creates a bounded evaluation manifest for an approved synthetic fixture set. The server stores no raw fixture or generated provider content.')]
    #[Response(status: 201, description: 'Created AI evaluation run.', type: 'array')]
    public function store(StoreAiEvaluationRequest $request, AiEvaluationAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $request->validated()), 201);
    }

    #[Endpoint(operationId: 'v1.platform.ai-evaluations.show', title: 'View an AI evaluation', description: 'Returns safe lifecycle, release-gate, issue-code and rubric projections for one evaluation run.')]
    #[Response(status: 200, description: 'AI evaluation run.', type: 'array')]
    public function show(Request $request, string $evaluation, AiEvaluationAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $evaluation));
    }

    #[Endpoint(operationId: 'v1.platform.ai-evaluations.run', title: 'Run an AI evaluation', description: 'Runs the deterministic local safety fixture contract for accuracy, grounding, bias, refusal, hallucination and privacy dimensions.')]
    #[Response(status: 200, description: 'Completed AI evaluation run.', type: 'array')]
    public function run(Request $request, string $evaluation, AiEvaluationAction $action): JsonResponse
    {
        return ApiResponse::data($action->run($this->identity($request), $evaluation));
    }

    #[Endpoint(operationId: 'v1.platform.ai-evaluations.review', title: 'Review an AI evaluation', description: 'Records an independent human approval or rejection. Approval is blocked unless every release-gate dimension passes.')]
    #[Response(status: 200, description: 'Reviewed AI evaluation run.', type: 'array')]
    public function review(ReviewAiEvaluationRequest $request, string $evaluation, AiEvaluationAction $action): JsonResponse
    {
        return ApiResponse::data($action->review($this->identity($request), $evaluation, $request->string('decision')->toString(), $request->string('reason')->toString()));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
