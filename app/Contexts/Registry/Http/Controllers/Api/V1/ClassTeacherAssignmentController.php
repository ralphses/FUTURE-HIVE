<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\ClassTeacherAssignmentAction;
use App\Contexts\Registry\Http\Requests\RevokeClassTeacherAssignmentRequest;
use App\Contexts\Registry\Http\Requests\StoreClassTeacherAssignmentRequest;
use App\Contexts\Registry\Http\Requests\UpdateClassTeacherAssignmentRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClassTeacherAssignmentController
{
    public function index(Request $request, string $school, string $session, string $term, string $classArm, ClassTeacherAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term, $classArm));
    }

    public function show(Request $request, string $school, string $session, string $term, string $classArm, string $assignment, ClassTeacherAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $classArm, $assignment));
    }

    public function store(StoreClassTeacherAssignmentRequest $request, string $school, string $session, string $term, string $classArm, ClassTeacherAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $classArm, $request->validated()), 201);
    }

    public function update(UpdateClassTeacherAssignmentRequest $request, string $school, string $session, string $term, string $classArm, string $assignment, ClassTeacherAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $classArm, $assignment, $request->validated()));
    }

    public function revoke(RevokeClassTeacherAssignmentRequest $request, string $school, string $session, string $term, string $classArm, string $assignment, ClassTeacherAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->revoke($this->identity($request), $school, $session, $term, $classArm, $assignment, $request->string('reason')->toString()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
