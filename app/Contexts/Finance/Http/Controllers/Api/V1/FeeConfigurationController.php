<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FeeConfigurationAction;
use App\Contexts\Finance\Http\Requests\StoreFeeCategoryRequest;
use App\Contexts\Finance\Http\Requests\StoreFeePlanRequest;
use App\Contexts\Finance\Http\Requests\UpdateFeeCategoryRequest;
use App\Contexts\Finance\Http\Requests\UpdateFeePlanRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeeConfigurationController
{
    #[Endpoint(title: 'List fee categories', description: 'Lists school-scoped reusable fee categories without creating charges or invoices.')]
    #[Response(status: 200, description: 'Fee categories.', type: 'array')]
    public function categories(Request $request, string $school, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->categories($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create fee category', description: 'Creates a school-scoped fee category. Ownership, status and audit metadata are server-controlled.')]
    #[Response(status: 201, description: 'Created fee category.', type: 'array')]
    public function storeCategory(StoreFeeCategoryRequest $request, string $school, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->createCategory($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'Update fee category', description: 'Updates draft fee-category metadata in the trusted selected school.')]
    #[Response(status: 200, description: 'Updated fee category.', type: 'array')]
    public function updateCategory(UpdateFeeCategoryRequest $request, string $school, string $category, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateCategory($this->identity($request), $school, $category, $request->validated()));
    }

    #[Endpoint(title: 'List fee plans', description: 'Lists versioned school fee plans for an academic term without generating student charges.')]
    #[Response(status: 200, description: 'Fee plans.', type: 'array')]
    public function plans(Request $request, string $school, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->plans($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create fee plan', description: 'Creates a draft versioned fee plan with integer minor-unit amounts and server-controlled ownership.')]
    #[Response(status: 201, description: 'Created fee plan.', type: 'array')]
    public function storePlan(StoreFeePlanRequest $request, string $school, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->createPlan($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View fee plan', description: 'Returns safe versioned fee-plan configuration and ordered lines.')]
    #[Response(status: 200, description: 'Fee plan.', type: 'array')]
    public function showPlan(Request $request, string $school, string $plan, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->showPlan($this->identity($request), $school, $plan));
    }

    #[Endpoint(title: 'Update fee plan', description: 'Updates a draft fee plan only; active and retired plans remain immutable.')]
    #[Response(status: 200, description: 'Updated fee plan.', type: 'array')]
    public function updatePlan(UpdateFeePlanRequest $request, string $school, string $plan, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->updatePlan($this->identity($request), $school, $plan, $request->validated()));
    }

    #[Endpoint(title: 'Activate fee plan', description: 'Activates one draft fee plan for its school, term and scope. No billing records are created.')]
    #[Response(status: 200, description: 'Activated fee plan.', type: 'array')]
    public function activatePlan(Request $request, string $school, string $plan, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->activatePlan($this->identity($request), $school, $plan));
    }

    #[Endpoint(title: 'Retire fee plan', description: 'Retires an active fee plan while preserving historical configuration.')]
    #[Response(status: 200, description: 'Retired fee plan.', type: 'array')]
    public function retirePlan(Request $request, string $school, string $plan, FeeConfigurationAction $action): JsonResponse
    {
        return ApiResponse::data($action->retirePlan($this->identity($request), $school, $plan));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
