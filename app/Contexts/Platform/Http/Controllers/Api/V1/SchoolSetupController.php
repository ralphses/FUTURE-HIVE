<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SchoolSetupProgressAction;
use App\Contexts\Platform\Http\Requests\UpdateSchoolSetupItemRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolSetupController
{
    /** @response 200 array{data: array{school_id: string, items: array<int, array{key: string, status: string, completed_at: string|null}>, completed_count: int, total_count: int, completion_percentage: int}} */
    #[Endpoint(
        title: 'View school setup progress',
        description: 'Returns persisted onboarding checklist progress for the trusted selected school context.',
    )]
    public function index(Request $request, string $school, SchoolSetupProgressAction $progress): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return ApiResponse::data($progress->list($identity, $school));
    }

    /** @response 200 array{data: array{key: string, status: string, completed_at: string|null}} */
    #[Endpoint(
        title: 'Update school setup progress',
        description: 'Marks one approved onboarding checklist item pending or completed. It does not create school-domain records.',
    )]
    public function update(UpdateSchoolSetupItemRequest $request, string $school, string $item, SchoolSetupProgressAction $progress): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return ApiResponse::data($progress->update(
            $identity,
            $school,
            $item,
            $request->string('status')->toString(),
        ));
    }
}
