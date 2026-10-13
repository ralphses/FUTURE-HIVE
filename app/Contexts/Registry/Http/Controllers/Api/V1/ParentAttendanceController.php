<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\ParentAttendanceAction;
use App\Contexts\Registry\Http\Requests\ParentAttendanceRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class ParentAttendanceController
{
    #[Endpoint(title: 'Show parent attendance', description: 'Shows safe attendance summaries for the authenticated guardian’s active verified linked students. Draft and missing registers are excluded from attendance-rate calculations.')]
    #[Response(status: 200, description: 'Paginated parent attendance summaries.', type: 'array')]
    public function index(ParentAttendanceRequest $request, ParentAttendanceAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $request->validated()));
    }

    #[Endpoint(title: 'Show child attendance', description: 'Shows safe attendance for one active verified linked student. Unrelated, revoked and cross-school students fail generically.')]
    public function child(ParentAttendanceRequest $request, string $student, ParentAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->child($this->identity($request), $student, $request->validated()));
    }

    private function identity(ParentAttendanceRequest $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
