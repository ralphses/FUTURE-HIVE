<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinanceReconciliationAction;
use App\Contexts\Finance\Http\Requests\ResolveFinanceReconciliationExceptionRequest;
use App\Contexts\Finance\Http\Requests\StoreFinanceReconciliationRunRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceReconciliationController
{
    #[Endpoint(title: 'List reconciliation runs', description: 'Lists safe school-scoped reconciliation runs.')]
    public function index(Request $request, string $school, FinanceReconciliationAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create reconciliation run', description: 'Creates a deterministic finance reconciliation run.')]
    public function store(StoreFinanceReconciliationRunRequest $request, string $school, FinanceReconciliationAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View reconciliation run', description: 'Returns a safe reconciliation summary.')]
    public function show(Request $request, string $school, string $run, FinanceReconciliationAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $run));
    }

    #[Endpoint(title: 'List reconciliation items', description: 'Lists safe reconciliation matches and exceptions.')]
    public function items(Request $request, string $school, string $run, FinanceReconciliationAction $action): JsonResponse
    {
        return ApiResponse::data($action->items($this->identity($request), $school, $run));
    }

    #[Endpoint(title: 'Resolve reconciliation exception', description: 'Resolves an eligible reconciliation exception with a bounded reason.')]
    public function resolve(ResolveFinanceReconciliationExceptionRequest $request, string $school, string $run, string $item, FinanceReconciliationAction $action): JsonResponse
    {
        return ApiResponse::data($action->resolve($this->identity($request), $school, $run, $item, (string) $request->validated('reason')));
    }

    #[Endpoint(title: 'Cancel reconciliation run', description: 'Cancels an eligible reconciliation run.')]
    public function cancel(Request $request, string $school, string $run, FinanceReconciliationAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($this->identity($request), $school, $run));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
