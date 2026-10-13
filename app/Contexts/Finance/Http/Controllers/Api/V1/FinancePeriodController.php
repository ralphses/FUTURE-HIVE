<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinancePeriodAction;
use App\Contexts\Finance\Http\Requests\FinancePeriodReasonRequest;
use App\Contexts\Finance\Http\Requests\StoreFinancePostCloseAdjustmentRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancePeriodController
{
    #[Endpoint(title: 'List financial periods', description: 'Lists safe school-scoped financial close status.')]
    public function index(Request $request, string $school, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school));
    }

    #[Endpoint(title: 'View financial period', description: 'Returns a term-scoped financial close status and safe blockers.')]
    public function show(Request $request, string $school, string $session, string $term, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'Start financial close', description: 'Starts a controlled financial period close after blocker checks.')]
    public function startClose(Request $request, string $school, string $session, string $term, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->startClose($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'Complete financial close', description: 'Completes a controlled financial period close after terminal-source checks.')]
    public function completeClose(Request $request, string $school, string $session, string $term, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->completeClose($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'Reopen financial period', description: 'Reopens an eligible closed financial period with an audited reason.')]
    public function reopen(FinancePeriodReasonRequest $request, string $school, string $session, string $term, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->reopen($this->identity($request), $school, $session, $term, (string) $request->validated('reason')));
    }

    #[Endpoint(title: 'List post-close adjustments', description: 'Lists safe post-close adjustment requests.')]
    public function adjustments(Request $request, string $school, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->adjustments($this->identity($request), $school));
    }

    #[Endpoint(title: 'Request post-close adjustment', description: 'Requests a compensating post-close adjustment without mutating historical sources.')]
    public function storeAdjustment(StoreFinancePostCloseAdjustmentRequest $request, string $school, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->createAdjustment($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View post-close adjustment', description: 'Returns a safe post-close adjustment projection.')]
    public function showAdjustment(Request $request, string $school, string $adjustment, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->showAdjustment($this->identity($request), $school, $adjustment));
    }

    #[Endpoint(title: 'Approve post-close adjustment', description: 'Approves a post-close adjustment with maker-checker separation.')]
    public function approveAdjustment(Request $request, string $school, string $adjustment, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->approveAdjustment($this->identity($request), $school, $adjustment));
    }

    #[Endpoint(title: 'Reject post-close adjustment', description: 'Rejects a post-close adjustment with a bounded reason.')]
    public function rejectAdjustment(FinancePeriodReasonRequest $request, string $school, string $adjustment, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->rejectAdjustment($this->identity($request), $school, $adjustment, (string) $request->validated('reason')));
    }

    #[Endpoint(title: 'Apply post-close adjustment', description: 'Applies an approved post-close adjustment as a compensating ledger batch.')]
    public function applyAdjustment(Request $request, string $school, string $adjustment, FinancePeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->applyAdjustment($this->identity($request), $school, $adjustment));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
