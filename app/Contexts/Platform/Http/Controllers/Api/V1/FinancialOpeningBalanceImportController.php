<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\FinancialOpeningBalanceImportAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialOpeningBalanceImportController
{
    #[Endpoint(title: 'Archive financial opening balances', description: 'Archives validated school opening balances as unverified historical positions. It does not create payments, invoices, receipts or ledger transactions.')]
    #[Response(status: 200, description: 'A safe opening-balance archive status and row outcome summary.', type: 'array')]
    public function store(Request $request, string $school, string $import, FinancialOpeningBalanceImportAction $action): JsonResponse
    {
        return ApiResponse::data($action->import($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'View financial opening-balance import', description: 'Returns safe archive status, counts and row outcome codes without exposing balances, source values or internal identifiers.')]
    #[Response(status: 200, description: 'The safe opening-balance archive result.', type: 'array')]
    public function show(Request $request, string $school, string $import, FinancialOpeningBalanceImportAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $import));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
