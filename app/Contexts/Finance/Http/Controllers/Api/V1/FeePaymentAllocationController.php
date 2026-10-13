<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FeePaymentAllocationAction;
use App\Contexts\Finance\Http\Requests\StoreFeePaymentAllocationRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeePaymentAllocationController
{
    #[Endpoint(title: 'List payment allocations', description: 'Lists safe partial-payment allocations for an issued school invoice. No provider, receipt or ledger records are exposed.')]
    public function index(Request $request, string $school, string $invoice, FeePaymentAllocationAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school, $invoice));
    }

    #[Endpoint(title: 'Create payment allocation', description: 'Allocates an integer minor-unit payment amount against an issued school invoice or invoice line with transactional over-allocation protection.')]
    public function store(StoreFeePaymentAllocationRequest $request, string $school, string $invoice, FeePaymentAllocationAction $action): JsonResponse
    {
        return ApiResponse::data($action->allocate($this->identity($request), $school, $invoice, $request->validated()), 201);
    }

    #[Endpoint(title: 'View payment allocation', description: 'Returns a safe payment-allocation projection without exposing payment-provider or ledger data.')]
    public function show(Request $request, string $school, string $invoice, string $allocation, FeePaymentAllocationAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $invoice, $allocation));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
