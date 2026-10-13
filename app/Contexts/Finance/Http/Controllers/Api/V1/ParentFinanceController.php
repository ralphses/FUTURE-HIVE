<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\ParentFinanceAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParentFinanceController
{
    #[Endpoint(title: 'Show parent fee balances', description: 'Shows safe fee balances for active students linked to the authenticated verified guardian.')]
    #[Response(status: 200, description: 'Paginated safe fee balances.', type: 'array')]
    public function balances(Request $request, ParentFinanceAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->balances($this->identity($request)));
    }

    #[Endpoint(title: 'Show parent child fee balance', description: 'Shows a safe fee balance for one active student linked to the authenticated verified guardian.')]
    public function balanceChild(Request $request, string $student, ParentFinanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->balanceForChild($this->identity($request), $student));
    }

    #[Endpoint(title: 'Show parent receipts', description: 'Shows immutable issued receipts for active students linked to the authenticated verified guardian.')]
    #[Response(status: 200, description: 'Paginated immutable receipts.', type: 'array')]
    public function receipts(Request $request, ParentFinanceAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->receipts($this->identity($request)));
    }

    #[Endpoint(title: 'Show parent child receipts', description: 'Shows immutable issued receipts for one active student linked to the authenticated verified guardian.')]
    public function receiptsChild(Request $request, string $student, ParentFinanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->receiptsForChild($this->identity($request), $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
