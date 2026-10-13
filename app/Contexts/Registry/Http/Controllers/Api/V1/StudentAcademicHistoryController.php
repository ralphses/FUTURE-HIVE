<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentAcademicHistoryAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentAcademicHistoryController
{
    #[Endpoint(title: 'Show student academic history', description: 'Shows published immutable academic history for the authenticated student across active schools. Unpublished, incomplete and unrelated records are excluded.')]
    #[Response(status: 200, description: 'Safe student academic history.', type: 'array')]
    public function index(Request $request, StudentAcademicHistoryAction $action): JsonResponse
    {
        return ApiResponse::data($action->handle($this->identity($request)));
    }

    #[Endpoint(title: 'Show student academic history for a school', description: 'Shows published immutable academic history for the authenticated student in one active school. The school identifier cannot grant ownership or bypass student membership.')]
    #[Response(status: 200, description: 'Safe school-scoped academic history.', type: 'array')]
    public function school(Request $request, string $school, StudentAcademicHistoryAction $action): JsonResponse
    {
        return ApiResponse::data($action->school($this->identity($request), $school));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
