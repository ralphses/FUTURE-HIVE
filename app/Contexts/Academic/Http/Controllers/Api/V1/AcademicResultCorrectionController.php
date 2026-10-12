<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultCorrectionAction;
use App\Contexts\Academic\Http\Requests\CreateAcademicResultCorrectionRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicResultCorrectionController
{
    #[Endpoint(title: 'Request result correction', description: 'Creates a bounded correction request for a published result snapshot. It does not accept raw score edits or mutate the published snapshot.')]
    #[Response(status: 201, description: 'Result correction request.', type: 'array')]
    public function store(CreateAcademicResultCorrectionRequest $request, string $school, string $session, string $term, string $snapshot, AcademicResultCorrectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $snapshot, $request->string('reason')->trim()->toString()), 201);
    }

    #[Endpoint(title: 'List result corrections', description: 'Lists correction requests for one published result snapshot using safe public references and lifecycle metadata.')]
    #[Response(status: 200, description: 'Paginated correction requests.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, string $snapshot, AcademicResultCorrectionAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school, $session, $term, $snapshot));
    }

    #[Endpoint(title: 'View result correction', description: 'Shows one tenant-scoped correction request without exposing internal identifiers or source score values.')]
    #[Response(status: 200, description: 'Correction request.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $correction, AcademicResultCorrectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $correction));
    }

    #[Endpoint(title: 'Approve result correction', description: 'Approves a correction request for later recomputation through the existing completeness and publication gates.')]
    #[Response(status: 200, description: 'Approved correction request.', type: 'array')]
    public function approve(Request $request, string $school, string $session, string $term, string $correction, AcademicResultCorrectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->approve($this->identity($request), $school, $session, $term, $correction));
    }

    #[Endpoint(title: 'Reject result correction', description: 'Rejects a correction request while retaining the original published result snapshot.')]
    #[Response(status: 200, description: 'Rejected correction request.', type: 'array')]
    public function reject(Request $request, string $school, string $session, string $term, string $correction, AcademicResultCorrectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->reject($this->identity($request), $school, $session, $term, $correction));
    }

    #[Endpoint(title: 'Republish corrected result', description: 'Computes, gates and snapshots corrected source data as a new immutable publication while preserving the original snapshot.')]
    #[Response(status: 200, description: 'Applied correction and superseding snapshot.', type: 'array')]
    public function republish(Request $request, string $school, string $session, string $term, string $correction, AcademicResultCorrectionAction $action): JsonResponse
    {
        return ApiResponse::data($action->republish($this->identity($request), $school, $session, $term, $correction));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
