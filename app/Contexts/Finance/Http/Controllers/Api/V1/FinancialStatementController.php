<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinancialStatementAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialStatementController
{
    #[Endpoint(title: 'List financial statements', description: 'Lists safe school-scoped outstanding financial statements.')]
    public function index(Request $request, string $school, FinancialStatementAction $action): JsonResponse
    {
        return ApiResponse::data($action->forSchool($this->identity($request), $school));
    }

    #[Endpoint(title: 'View student financial statement', description: 'Returns a safe outstanding statement for an authorized student.')]
    public function student(Request $request, string $school, string $student, FinancialStatementAction $action): JsonResponse
    {
        return ApiResponse::data($action->forStudent($this->identity($request), $school, $student));
    }

    #[Endpoint(title: 'View guardian financial statement', description: 'Returns a safe consolidated statement for authorized linked students.')]
    public function guardian(Request $request, string $school, string $guardian, FinancialStatementAction $action): JsonResponse
    {
        return ApiResponse::data($action->forGuardian($this->identity($request), $school, $guardian));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
