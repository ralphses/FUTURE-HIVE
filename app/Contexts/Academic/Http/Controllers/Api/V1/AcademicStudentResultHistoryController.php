<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicStudentResultHistoryAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicStudentResultHistoryController
{
    #[Endpoint(title: 'List result history', description: 'Lists published immutable result snapshot versions for the trusted school term. Draft, blocked and unpublished results are excluded. No student or guardian portal access is added.')]
    #[Response(status: 200, description: 'Paginated published result history.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, AcademicStudentResultHistoryAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'List student result history', description: 'Lists published immutable result versions and safe subject entries for one trusted student. Teachers see only assigned offerings; internal identifiers and portal access are excluded.')]
    #[Response(status: 200, description: 'Paginated student result history.', type: 'array')]
    public function student(Request $request, string $school, string $session, string $term, string $student, AcademicStudentResultHistoryAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->student($this->identity($request), $school, $session, $term, $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
