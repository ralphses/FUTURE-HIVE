<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultComputationAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicResultRunController
{
    #[Endpoint(title: 'Compute academic results', description: 'Computes a deterministic term-wide result projection from approved assessment submissions. Source scores and submissions are not changed.')]
    #[Response(status: 200, description: 'The result run and safe derived entries.', type: 'array')]
    public function store(Request $request, string $school, string $session, string $term, AcademicResultComputationAction $action): JsonResponse
    {
        return ApiResponse::data($action->compute($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'List result runs', description: 'Lists safe result-computation runs for one school term.')]
    #[Response(status: 200, description: 'Result runs.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, AcademicResultComputationAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'View a result run', description: 'Shows one result run and its safe derived entries.')]
    #[Response(status: 200, description: 'Result run details.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $run, AcademicResultComputationAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $run));
    }

    #[Endpoint(title: 'View a student result', description: 'Shows the visible result entries for one student in a result run.')]
    #[Response(status: 200, description: 'Student result entries.', type: 'array')]
    public function student(Request $request, string $school, string $session, string $term, string $run, string $student, AcademicResultComputationAction $action): JsonResponse
    {
        return ApiResponse::data($action->student($this->identity($request), $school, $session, $term, $run, $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
