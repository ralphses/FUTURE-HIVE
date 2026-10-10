<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicPromotionRuleAction;
use App\Contexts\Academic\Http\Requests\UpsertAcademicPromotionRuleRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicPromotionRuleController
{
    public function index(Request $request, string $school, AcademicPromotionRuleAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    public function show(Request $request, string $school, string $rule, AcademicPromotionRuleAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $rule));
    }

    public function store(UpsertAcademicPromotionRuleRequest $request, string $school, AcademicPromotionRuleAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    public function update(UpsertAcademicPromotionRuleRequest $request, string $school, string $rule, AcademicPromotionRuleAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $rule, $request->validated()));
    }

    public function activate(Request $request, string $school, string $rule, AcademicPromotionRuleAction $action): JsonResponse
    {
        return ApiResponse::data($action->activate($this->identity($request), $school, $rule));
    }

    public function deactivate(Request $request, string $school, string $rule, AcademicPromotionRuleAction $action): JsonResponse
    {
        return ApiResponse::data($action->deactivate($this->identity($request), $school, $rule));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
