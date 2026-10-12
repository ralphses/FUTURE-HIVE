<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAssessmentScoreAuditAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAssessmentScoreAuditController
{
    #[Endpoint(title: 'View score audit history', description: 'Shows immutable score changes for the selected assessment instance in deterministic order. Access is limited to the trusted school and the teacher’s assigned offering or approved academic leadership.')]
    #[Response(status: 200, description: 'The safe paginated score history.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentScoreAuditAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    #[Endpoint(title: 'View one score’s history', description: 'Shows the append-only revision history for one student and policy component score. Stale writes are rejected before mutation and the response contains only safe public references and state transitions.')]
    #[Response(status: 200, description: 'The safe paginated history for one score.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $offering, string $instance, string $student, string $component, AcademicAssessmentScoreAuditAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $school, $session, $term, $offering, $instance, $student, $component));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
