<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\CareerGuidanceAction;
use App\Contexts\Academic\Http\Requests\StoreCareerGuidanceSuggestionRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerGuidanceController
{
    #[Endpoint(title: 'Show career guidance context', description: 'Returns approved-source availability for the authenticated student. Missing approved requirements fail closed as guidance_unavailable; no admission claim is made.')]
    #[Response(status: 200, description: 'Safe career guidance context.', type: 'array')]
    public function index(Request $request, CareerGuidanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request)));
    }

    #[Endpoint(title: 'Request career guidance suggestions', description: 'Returns ephemeral, unreviewed career and subject guidance from approved sources only. It never guarantees admission or mutates academic records.')]
    #[Response(status: 200, description: 'Safe unreviewed career guidance suggestion.', type: 'array')]
    public function suggest(StoreCareerGuidanceSuggestionRequest $request, CareerGuidanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->suggest($this->identity($request), $request->validated()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
