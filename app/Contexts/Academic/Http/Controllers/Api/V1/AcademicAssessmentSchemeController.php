<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAssessmentSchemeAction;
use App\Contexts\Academic\Http\Requests\UpdateAcademicAssessmentSchemeRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAssessmentSchemeController
{
    public function show(Request $request, string $school, string $session, string $term, string $offering, AcademicAssessmentSchemeAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $offering));
    }

    public function update(UpdateAcademicAssessmentSchemeRequest $request, string $school, string $session, string $term, string $offering, AcademicAssessmentSchemeAction $action): JsonResponse
    {
        return ApiResponse::data($action->replace($this->identity($request), $school, $session, $term, $offering, $request->validated()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
