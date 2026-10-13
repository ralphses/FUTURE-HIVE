<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentDashboardAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentDashboardController
{
    #[Endpoint(title: 'Show student dashboard', description: 'Shows the authenticated student’s safe school placement, published attendance availability and published result summaries. Peer and unpublished data are excluded.')]
    public function __invoke(Request $request, StudentDashboardAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::data($action->handle($identity));
    }
}
