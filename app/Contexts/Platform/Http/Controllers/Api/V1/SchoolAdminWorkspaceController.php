<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SchoolAdminWorkspaceAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolAdminWorkspaceController
{
    #[Endpoint(title: 'View school admin workspace', description: 'Returns a read-only school-scoped workspace of safe setup, lifecycle, staff, membership, role and academic-context indicators for school administrators. It never exposes contacts or source records and never mutates data.')]
    #[Response(status: 200, description: 'Safe school admin workspace projection.', type: 'array')]
    public function __invoke(Request $request, string $school, SchoolAdminWorkspaceAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless(is_a($identity, 'App\\Contexts\\Identity\\Domain\\Models\\UserIdentity'), 401);

        return ApiResponse::data($action->execute($identity, $school));
    }
}
