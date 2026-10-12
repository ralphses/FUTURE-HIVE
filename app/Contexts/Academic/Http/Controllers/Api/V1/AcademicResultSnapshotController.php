<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultSnapshotAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicResultSnapshotController
{
    #[Endpoint(title: 'Create an immutable result snapshot', description: 'Creates one immutable published-result snapshot from a complete published result run. Source academic records and school context remain unchanged; student and guardian delivery is not included.')]
    #[Response(status: 201, description: 'Created immutable result snapshot.', type: 'array')]
    public function store(Request $request, string $school, string $session, string $term, string $run, AcademicResultSnapshotAction $action): JsonResponse
    {
        $identity = $this->identity($request);

        return ApiResponse::data($action->create($identity, $school, $session, $term, $run), 201);
    }

    #[Endpoint(title: 'List result snapshots', description: 'Lists immutable published-result snapshots for the trusted school and academic term without exposing internal identifiers or source records.')]
    #[Response(status: 200, description: 'Paginated result snapshots.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, AcademicResultSnapshotAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'View a result snapshot', description: 'Shows one immutable result snapshot and safe derived entries within the trusted academic scope.')]
    #[Response(status: 200, description: 'Immutable result snapshot.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $snapshot, AcademicResultSnapshotAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $snapshot));
    }

    #[Endpoint(title: 'View one student result snapshot', description: 'Shows the safe immutable result entries for one trusted student without adding student or guardian portal access.')]
    #[Response(status: 200, description: 'Student result snapshot entries.', type: 'array')]
    public function student(Request $request, string $school, string $session, string $term, string $snapshot, string $student, AcademicResultSnapshotAction $action): JsonResponse
    {
        return ApiResponse::data($action->student($this->identity($request), $school, $session, $term, $snapshot, $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
