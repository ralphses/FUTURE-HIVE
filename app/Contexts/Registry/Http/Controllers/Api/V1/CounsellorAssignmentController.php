<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\CounsellorAssignmentAction;
use App\Contexts\Registry\Http\Requests\RevokeCounsellorAssignmentRequest;
use App\Contexts\Registry\Http\Requests\StoreCounsellorAssignmentRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CounsellorAssignmentController
{
    public function index(Request $request, string $school, CounsellorAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    public function show(Request $request, string $school, string $assignment, CounsellorAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $assignment));
    }

    public function store(StoreCounsellorAssignmentRequest $request, string $school, CounsellorAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    public function revoke(RevokeCounsellorAssignmentRequest $request, string $school, string $assignment, CounsellorAssignmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->revoke($this->identity($request), $school, $assignment, $request->string('reason')->toString()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
