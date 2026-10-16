<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\CounsellingDevelopmentPlanAction;
use App\Contexts\Registry\Http\Requests\DevelopmentPlanTransitionRequest;
use App\Contexts\Registry\Http\Requests\ShareDevelopmentPlanRequest;
use App\Contexts\Registry\Http\Requests\StoreCounsellingDevelopmentPlanRequest;
use App\Contexts\Registry\Http\Requests\UpdateCounsellingDevelopmentPlanRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CounsellingDevelopmentPlanController
{
    public function index(Request $request, string $school, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    public function store(StoreCounsellingDevelopmentPlanRequest $request, string $school, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    public function show(Request $request, string $school, string $plan, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $plan));
    }

    public function update(UpdateCounsellingDevelopmentPlanRequest $request, string $school, string $plan, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $plan, $request->validated()));
    }

    public function activate(DevelopmentPlanTransitionRequest $request, string $school, string $plan, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $plan, 'active', $request->string('reason')->toString()));
    }

    public function close(DevelopmentPlanTransitionRequest $request, string $school, string $plan, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $plan, 'closed', $request->string('reason')->toString()));
    }

    public function share(ShareDevelopmentPlanRequest $request, string $school, string $plan, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->share($this->identity($request), $school, $plan, $request->string('scope')->toString()));
    }

    public function archive(DevelopmentPlanTransitionRequest $request, string $school, string $plan, CounsellingDevelopmentPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $plan, 'archived', $request->string('reason')->toString()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
