<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinancialLedgerAction;
use App\Contexts\Finance\Http\Requests\StoreFinancialLedgerBatchRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialLedgerController
{
    #[Endpoint(title: 'List financial ledger', description: 'Lists safe school-scoped ledger batches and entries without exposing internal identifiers or unrelated finance records.')]
    public function index(Request $request, string $school, FinancialLedgerAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create ledger batch', description: 'Creates a pending balanced double-entry batch for a trusted source. Posting is explicit and idempotent.')]
    public function store(StoreFinancialLedgerBatchRequest $request, string $school, FinancialLedgerAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View ledger batch', description: 'Returns a safe ledger batch projection and its public entries.')]
    public function show(Request $request, string $school, string $batch, FinancialLedgerAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $batch));
    }

    #[Endpoint(title: 'Post ledger batch', description: 'Posts a pending balanced ledger batch while retaining immutable historical entries.')]
    public function post(Request $request, string $school, string $batch, FinancialLedgerAction $action): JsonResponse
    {
        return ApiResponse::data($action->post($this->identity($request), $school, $batch));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
