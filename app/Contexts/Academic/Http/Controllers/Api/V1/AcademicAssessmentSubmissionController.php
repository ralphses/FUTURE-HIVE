<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAssessmentSubmissionAction;
use App\Contexts\Academic\Http\Requests\ReturnAcademicAssessmentSubmissionRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAssessmentSubmissionController
{
    public function show(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentSubmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function submit(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentSubmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->submit($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function review(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentSubmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->review($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function returnSubmission(ReturnAcademicAssessmentSubmissionRequest $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentSubmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->returnSubmission($this->identity($request), $school, $session, $term, $offering, $instance, (string) $request->validated('reason')));
    }

    public function approve(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentSubmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->approve($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function resubmit(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentSubmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->resubmit($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
