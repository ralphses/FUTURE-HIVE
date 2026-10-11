<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\TeacherWorklistAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeacherWorklistController
{
    #[Endpoint(title: 'View teacher worklist', description: 'Lists the authenticated teacher’s active class-teacher and subject-teaching assignments in the selected school. Pending actions are shown only when their owning records exist; this read-only endpoint does not create academic records.')]
    #[QueryParameter(name: 'page', description: 'Page number, starting at 1.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Number of work items per page. The server applies the standard maximum.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'A paginated list of the authenticated teacher’s safe assigned work items.', type: 'array')]
    public function index(Request $request, string $school, TeacherWorklistAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return ApiResponse::paginated($action->index($identity, $school, $request->integer('page', 1), ApiResponse::perPage($request->integer('per_page', ApiResponse::DEFAULT_PAGE_SIZE))));
    }
}
