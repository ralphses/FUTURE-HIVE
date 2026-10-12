<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAssessmentAccessWindowAction;
use App\Contexts\Academic\Http\Requests\PutAcademicAssessmentAccessWindowRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAssessmentAccessWindowController
{
    public function show(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentAccessWindowAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function update(PutAcademicAssessmentAccessWindowRequest $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentAccessWindowAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $offering, $instance, $request->validated()));
    }

    public function activate(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentAccessWindowAction $action): JsonResponse
    {
        return ApiResponse::data($action->activate($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function close(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentAccessWindowAction $action): JsonResponse
    {
        return ApiResponse::data($action->close($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
