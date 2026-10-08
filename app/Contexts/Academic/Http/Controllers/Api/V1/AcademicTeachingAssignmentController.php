<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicTeachingAssignmentAction;
use App\Contexts\Academic\Http\Requests\RevokeAcademicTeachingAssignmentRequest;
use App\Contexts\Academic\Http\Requests\StoreAcademicTeachingAssignmentRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicTeachingAssignmentRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicTeachingAssignmentController
{
    public function index(Request $request, string $school, string $session, string $term, string $offering, AcademicTeachingAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term, $offering));
    }

    public function show(Request $request, string $school, string $session, string $term, string $offering, string $assignment, AcademicTeachingAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $offering, $assignment));
    }

    public function store(StoreAcademicTeachingAssignmentRequest $request, string $school, string $session, string $term, string $offering, AcademicTeachingAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $offering, $request->validated()), 201);
    }

    public function update(UpdateAcademicTeachingAssignmentRequest $request, string $school, string $session, string $term, string $offering, string $assignment, AcademicTeachingAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $offering, $assignment, $request->validated()));
    }

    public function revoke(RevokeAcademicTeachingAssignmentRequest $request, string $school, string $session, string $term, string $offering, string $assignment, AcademicTeachingAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->revoke($this->identity($request), $school, $session, $term, $offering, $assignment, $request->string('reason')->toString()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
