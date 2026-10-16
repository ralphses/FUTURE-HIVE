<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\LessonPlanningSuggestionAction;
use App\Contexts\Academic\Http\Requests\StoreLessonPlanningSuggestionRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class LessonPlanningSuggestionController
{
    #[Endpoint(title: 'Generate a lesson-planning suggestion', description: 'Returns an ephemeral, bounded lesson-planning suggestion from the trusted school academic context. The result is unreviewed draft content and is not persisted or treated as approved curriculum. No external AI provider is called by the local implementation.')]
    #[Response(status: 201, description: 'The unreviewed lesson-planning suggestion.', type: 'array')]
    public function store(StoreLessonPlanningSuggestionRequest $request, string $school, LessonPlanningSuggestionAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::data($action->suggest($identity, $school, $request->validated()), 201);
    }
}
