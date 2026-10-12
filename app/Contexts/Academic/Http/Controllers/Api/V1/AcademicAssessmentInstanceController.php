<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAssessmentInstanceAction;
use App\Contexts\Academic\Http\Requests\CreateAcademicAssessmentInstanceRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicAssessmentInstanceRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAssessmentInstanceController
{
    public function index(Request $request, string $school, string $session, string $term, string $offering, AcademicAssessmentInstanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term, $offering));
    }

    public function store(CreateAcademicAssessmentInstanceRequest $request, string $school, string $session, string $term, string $offering, AcademicAssessmentInstanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $offering, $request->validated()), 201);
    }

    public function show(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentInstanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $offering, $instance));
    }

    public function update(UpdateAcademicAssessmentInstanceRequest $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentInstanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $offering, $instance, $request->validated()));
    }

    public function open(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentInstanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $session, $term, $offering, $instance, 'open'));
    }

    public function close(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentInstanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $session, $term, $offering, $instance, 'closed'));
    }

    public function cancel(Request $request, string $school, string $session, string $term, string $offering, string $instance, AcademicAssessmentInstanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $session, $term, $offering, $instance, 'cancelled'));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
