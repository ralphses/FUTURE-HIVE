<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StaffProfileAction;
use App\Contexts\Registry\Http\Requests\ChangeStaffEmploymentStatusRequest;
use App\Contexts\Registry\Http\Requests\StoreStaffProfileRequest;
use App\Contexts\Registry\Http\Requests\UpdateStaffProfileRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffProfileController
{
    public function index(Request $request, string $school, StaffProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    public function show(Request $request, string $school, string $staff, StaffProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $staff));
    }

    public function store(StoreStaffProfileRequest $request, string $school, StaffProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    public function update(UpdateStaffProfileRequest $request, string $school, string $staff, StaffProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $staff, $request->validated()));
    }

    public function activate(ChangeStaffEmploymentStatusRequest $request, string $school, string $staff, StaffProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $staff, 'active', $request->validated('reason')));
    }

    public function suspend(ChangeStaffEmploymentStatusRequest $request, string $school, string $staff, StaffProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $staff, 'suspended', $request->validated('reason')));
    }

    public function end(ChangeStaffEmploymentStatusRequest $request, string $school, string $staff, StaffProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $staff, 'ended', $request->validated('reason')));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
