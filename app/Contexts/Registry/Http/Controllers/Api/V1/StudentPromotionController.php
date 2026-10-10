<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentPromotionAction;
use App\Contexts\Registry\Http\Requests\StoreStudentPromotionCycleRequest;
use App\Contexts\Registry\Http\Requests\StoreStudentPromotionDecisionRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentPromotionController
{
    #[Endpoint(title: 'List promotion cycles', description: 'Lists explicit promotion and repetition cycles for the selected school. Decisions are human-entered and no scores are calculated.')]
    public function index(Request $request, string $school, StudentPromotionAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create a promotion cycle', description: 'Creates a draft cycle between two school academic terms. It creates no student decisions until they are explicitly submitted.')]
    public function store(StoreStudentPromotionCycleRequest $request, string $school, StudentPromotionAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View a promotion cycle', description: 'Shows one school-scoped cycle and its explicit student decisions.')]
    public function show(Request $request, string $school, string $cycle, StudentPromotionAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $cycle));
    }

    #[Endpoint(title: 'Add a promotion decision', description: 'Adds one explicit promote or repeat decision for an eligible active student. This does not apply placement.')]
    public function decision(StoreStudentPromotionDecisionRequest $request, string $school, string $cycle, StudentPromotionAction $action): JsonResponse
    {
        return ApiResponse::data($action->addDecision($this->identity($request), $school, $cycle, $request->validated()), 201);
    }

    #[Endpoint(title: 'Approve a promotion cycle', description: 'Approves a proposed cycle for later application by an authorized leader.')]
    public function approve(Request $request, string $school, string $cycle, StudentPromotionAction $action): JsonResponse
    {
        return ApiResponse::data($action->approve($this->identity($request), $school, $cycle));
    }

    #[Endpoint(title: 'Apply a promotion cycle', description: 'Applies approved decisions transactionally, ending source enrollments and creating target placements with capacity checks.')]
    public function apply(Request $request, string $school, string $cycle, StudentPromotionAction $action): JsonResponse
    {
        return ApiResponse::data($action->apply($this->identity($request), $school, $cycle));
    }

    #[Endpoint(title: 'Roll back a promotion cycle', description: 'Explicitly reverses an applied cycle only before downstream academic results exist. The action is audited and row-locked.')]
    public function rollback(Request $request, string $school, string $cycle, StudentPromotionAction $action): JsonResponse
    {
        return ApiResponse::data($action->rollback($this->identity($request), $school, $cycle));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
