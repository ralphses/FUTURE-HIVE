<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FeePaymentEvidenceAction;
use App\Contexts\Finance\Http\Requests\StoreFeePaymentEvidenceRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeePaymentEvidenceController
{
    #[Endpoint(title: 'List payment evidence', description: 'Lists safe manual payment evidence for an issued invoice without treating it as settlement.')]
    public function index(Request $request, string $school, string $invoice, FeePaymentEvidenceAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school, $invoice));
    }

    #[Endpoint(title: 'Create payment evidence', description: 'Records bounded manual payment evidence for later authorized review without posting allocations or ledger activity.')]
    public function store(StoreFeePaymentEvidenceRequest $request, string $school, string $invoice, FeePaymentEvidenceAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $invoice, $request->validated()), 201);
    }

    #[Endpoint(title: 'View payment evidence', description: 'Returns safe manual payment evidence and an authorized short-lived file link when available.')]
    public function show(Request $request, string $school, string $invoice, string $evidence, FeePaymentEvidenceAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $invoice, $evidence));
    }

    #[Endpoint(title: 'Verify payment evidence', description: 'Marks manual payment evidence as verified historical evidence without creating a payment, allocation or ledger entry.')]
    public function verify(Request $request, string $school, string $invoice, string $evidence, FeePaymentEvidenceAction $action): JsonResponse
    {
        return ApiResponse::data($action->verify($this->identity($request), $school, $invoice, $evidence));
    }

    #[Endpoint(title: 'Reject payment evidence', description: 'Rejects manual payment evidence with retained history and a safe bounded reason.')]
    public function reject(Request $request, string $school, string $invoice, string $evidence, FeePaymentEvidenceAction $action): JsonResponse
    {
        return ApiResponse::data($action->reject($this->identity($request), $school, $invoice, $evidence, (string) $request->input('reason', 'rejected_by_authorized_finance_user')));
    }

    #[Endpoint(title: 'Cancel payment evidence', description: 'Cancels pending manual payment evidence without changing invoice or payment state.')]
    public function cancel(Request $request, string $school, string $invoice, string $evidence, FeePaymentEvidenceAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($this->identity($request), $school, $invoice, $evidence));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
