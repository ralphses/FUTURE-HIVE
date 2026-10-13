<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinanceReceiptAction;
use App\Contexts\Finance\Http\Requests\StoreFinanceReceiptRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceReceiptController
{
    #[Endpoint(title: 'List receipts', description: 'Lists safe immutable receipts within the trusted school scope.')]
    public function index(Request $request, string $school, FinanceReceiptAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create receipt', description: 'Creates an idempotent receipt from verified payment allocation or verified manual payment evidence.')]
    public function store(StoreFinanceReceiptRequest $request, string $school, FinanceReceiptAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View receipt', description: 'Returns a safe immutable receipt projection.')]
    public function show(Request $request, string $school, string $receipt, FinanceReceiptAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $receipt));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
