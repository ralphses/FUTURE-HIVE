<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentWellbeingSummaryAction;
use App\Contexts\Registry\Http\Requests\WellbeingSummaryRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentWellbeingSummaryController
{
    public function index(WellbeingSummaryRequest $request, string $school, StudentWellbeingSummaryAction $action): JsonResponse
    {
        $input = $request->validated();

        return ApiResponse::data($action->index($this->identity($request), $school, (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25)));
    }

    public function show(Request $request, string $school, string $student, StudentWellbeingSummaryAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
