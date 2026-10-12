<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAssessmentScoreAction;
use App\Contexts\Academic\Http\Requests\PutAcademicAssessmentScoreRequest;
use App\Contexts\Academic\Http\Requests\PutAcademicAssessmentScoresRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAssessmentScoreController
{
    public function index(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentScoreAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function update(PutAcademicAssessmentScoresRequest $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentScoreAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $offering, $instance, $request->validated()));
    }

    public function updateOne(PutAcademicAssessmentScoreRequest $request, string $school, string $session, string $term, string $offering, string $instance, string $student, string $component, AcademicAssessmentScoreAction $action): JsonResponse
    {
        $payload = $request->validated();

        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $offering, $instance, ['entries' => [[...$payload, 'student_id' => $student, 'component_id' => $component]]]));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
