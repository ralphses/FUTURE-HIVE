<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicInsightSuggestionAction;
use App\Contexts\Academic\Http\Requests\StoreAcademicInsightSuggestionRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class AcademicInsightSuggestionController
{
    #[Endpoint(title: 'Generate an academic insight suggestion', description: 'Returns a bounded, explainable and unreviewed academic insight suggestion from authorized published/approved projections. It is ephemeral and cannot mutate academic records or create automated penalties.')]
    #[Response(status: 201, description: 'The unreviewed academic insight suggestion.', type: 'array')]
    public function store(StoreAcademicInsightSuggestionRequest $request, string $school, AcademicInsightSuggestionAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::data($action->suggest($identity, $school, $request->validated()), 201);
    }
}
