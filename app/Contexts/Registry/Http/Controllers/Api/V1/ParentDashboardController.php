<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\ParentDashboardAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParentDashboardController
{
    #[Endpoint(title: 'Show parent dashboard', description: 'Shows a safe read-only dashboard for the authenticated guardian and active verified student relationships. No guardian identity or school selector is accepted.')]
    #[Response(status: 200, description: 'Paginated authorized children.', type: 'array')]
    public function index(Request $request, ParentDashboardAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request)));
    }

    #[Endpoint(title: 'Show parent child dashboard', description: 'Shows a safe read-only dashboard for one active verified linked student. Unrelated and revoked relationships fail generically.')]
    public function child(Request $request, string $student, ParentDashboardAction $action): JsonResponse
    {
        return ApiResponse::data($action->childView($this->identity($request), $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
