<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinanceCompensationAction;
use App\Contexts\Finance\Http\Requests\StoreFinanceCompensationRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceCompensationController
{
    #[Endpoint(title: 'List reversals', description: 'Lists safe school-scoped reversal requests.')]
    public function reversalsIndex(Request $request, string $school, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school, 'reversal'));
    }

    #[Endpoint(title: 'Create reversal', description: 'Requests a compensating reversal for an eligible posted financial source.')]
    public function reversalsStore(StoreFinanceCompensationRequest $request, string $school, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, 'reversal', $request->validated()), 201);
    }

    #[Endpoint(title: 'View reversal', description: 'Returns a safe reversal request projection.')]
    public function reversalsShow(Request $request, string $school, string $reversal, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, 'reversal', $reversal));
    }

    #[Endpoint(title: 'Approve reversal', description: 'Approves a reversal with maker-checker separation.')]
    public function approveReversal(Request $request, string $school, string $reversal, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->approve($this->identity($request), $school, 'reversal', $reversal));
    }

    #[Endpoint(title: 'Reject reversal', description: 'Rejects a reversal request with a bounded reason.')]
    public function rejectReversal(Request $request, string $school, string $reversal, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->reject($this->identity($request), $school, 'reversal', $reversal, (string) $request->input('reason')));
    }

    #[Endpoint(title: 'Apply reversal', description: 'Applies an approved reversal as an immutable compensating ledger batch.')]
    public function applyReversal(Request $request, string $school, string $reversal, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->apply($this->identity($request), $school, 'reversal', $reversal));
    }

    #[Endpoint(title: 'List refunds', description: 'Lists safe school-scoped refund requests.')]
    public function refundsIndex(Request $request, string $school, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school, 'refund'));
    }

    #[Endpoint(title: 'Create refund', description: 'Requests a compensating refund for an eligible verified payment source.')]
    public function refundsStore(StoreFinanceCompensationRequest $request, string $school, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, 'refund', $request->validated()), 201);
    }

    #[Endpoint(title: 'View refund', description: 'Returns a safe refund request projection.')]
    public function refundsShow(Request $request, string $school, string $refund, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, 'refund', $refund));
    }

    #[Endpoint(title: 'Approve refund', description: 'Approves a refund with maker-checker separation.')]
    public function approveRefund(Request $request, string $school, string $refund, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->approve($this->identity($request), $school, 'refund', $refund));
    }

    #[Endpoint(title: 'Reject refund', description: 'Rejects a refund request with a bounded reason.')]
    public function rejectRefund(Request $request, string $school, string $refund, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->reject($this->identity($request), $school, 'refund', $refund, (string) $request->input('reason')));
    }

    #[Endpoint(title: 'Apply refund', description: 'Applies an approved refund as an immutable compensating ledger batch.')]
    public function applyRefund(Request $request, string $school, string $refund, FinanceCompensationAction $action): JsonResponse
    {
        return ApiResponse::data($action->apply($this->identity($request), $school, 'refund', $refund));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
