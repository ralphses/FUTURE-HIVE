<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FeeAdjustmentAction;
use App\Contexts\Finance\Http\Requests\AdjustmentDecisionRequest;
use App\Contexts\Finance\Http\Requests\StoreFeeAdjustmentProgramRequest;
use App\Contexts\Finance\Http\Requests\StoreFeeInvoiceAdjustmentRequest;
use App\Contexts\Finance\Http\Requests\UpdateFeeAdjustmentProgramRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeeAdjustmentController
{
    #[Endpoint(title: 'List adjustment programs', description: 'Lists safe school-scoped discount, scholarship and waiver programs.')]
    public function programs(Request $request, string $school, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->programs($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create adjustment program', description: 'Creates a draft school-scoped adjustment program. It does not change invoices.')]
    public function storeProgram(StoreFeeAdjustmentProgramRequest $request, string $school, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->createProgram($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'Update adjustment program', description: 'Updates draft adjustment-program metadata only.')]
    public function updateProgram(UpdateFeeAdjustmentProgramRequest $request, string $school, string $program, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateProgram($this->identity($request), $school, $program, $request->validated()));
    }

    #[Endpoint(title: 'Activate adjustment program', description: 'Activates a draft adjustment program for future approved invoice adjustments.')]
    public function activateProgram(Request $request, string $school, string $program, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->activateProgram($this->identity($request), $school, $program));
    }

    #[Endpoint(title: 'Retire adjustment program', description: 'Retires an active adjustment program while retaining its history.')]
    public function retireProgram(Request $request, string $school, string $program, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->retireProgram($this->identity($request), $school, $program));
    }

    #[Endpoint(title: 'List invoice adjustments', description: 'Lists safe discount, scholarship and waiver records for a draft or issued invoice.')]
    public function adjustments(Request $request, string $school, string $invoice, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->adjustments($this->identity($request), $school, $invoice));
    }

    #[Endpoint(title: 'Request invoice adjustment', description: 'Requests a bounded adjustment against a draft invoice; approval is required before application.')]
    public function storeAdjustment(StoreFeeInvoiceAdjustmentRequest $request, string $school, string $invoice, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->request($this->identity($request), $school, $invoice, $request->validated()), 201);
    }

    #[Endpoint(title: 'Approve invoice adjustment', description: 'Approves a pending adjustment without changing the invoice total.')]
    public function approve(Request $request, string $school, string $invoice, string $adjustment, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->approve($this->identity($request), $school, $invoice, $adjustment));
    }

    #[Endpoint(title: 'Reject invoice adjustment', description: 'Rejects a pending or approved adjustment using a bounded reason.')]
    public function reject(AdjustmentDecisionRequest $request, string $school, string $invoice, string $adjustment, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->reject($this->identity($request), $school, $invoice, $adjustment, $request->validated('reason')));
    }

    #[Endpoint(title: 'Apply invoice adjustment', description: 'Applies an approved adjustment to a draft invoice using integer minor units. Issued invoices remain immutable.')]
    public function apply(Request $request, string $school, string $invoice, string $adjustment, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->apply($this->identity($request), $school, $invoice, $adjustment));
    }

    #[Endpoint(title: 'Cancel invoice adjustment', description: 'Cancels a pending or approved adjustment without changing the invoice total.')]
    public function cancel(AdjustmentDecisionRequest $request, string $school, string $invoice, string $adjustment, FeeAdjustmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($this->identity($request), $school, $invoice, $adjustment, $request->validated('reason')));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
