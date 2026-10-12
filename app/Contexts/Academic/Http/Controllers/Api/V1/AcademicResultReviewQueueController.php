<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultReviewQueueAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicResultReviewQueueController
{
    #[Endpoint(title: 'List result review items', description: 'Lists computed result runs that academic leaders can review. The response contains safe references, counts and issue codes only; it never changes result data.')]
    #[Response(status: 200, description: 'A paginated result review queue.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, AcademicResultReviewQueueAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::paginated($action->index($identity, $school, $session, $term));
    }

    #[Endpoint(title: 'View a result review item', description: 'Shows one safe computed result run and its review issues for the selected term. This read-only view excludes raw scores and internal identifiers.')]
    #[Response(status: 200, description: 'A safe result review item with row-level issue projections.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $run, AcademicResultReviewQueueAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::data($action->show($identity, $school, $session, $term, $run));
    }
}
