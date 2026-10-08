<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SchoolLifecycleAction;
use App\Contexts\Platform\Http\Requests\ArchiveSchoolRequest;
use App\Contexts\Platform\Http\Requests\SchoolLifecycleTransitionRequest;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\SchoolLifecycleContext;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolLifecycleController
{
    #[Endpoint(title: 'View school lifecycle', description: 'Returns the current lifecycle state for the trusted selected school.')]
    public function show(Request $request, SchoolLifecycleAction $action): JsonResponse
    {
        $actor = $request->user();
        $context = $request->attributes->get('school_lifecycle_context');
        abort_unless($actor instanceof Authenticatable && $context instanceof SchoolLifecycleContext, 401);

        return ApiResponse::data($action->show($actor, $context));
    }

    public function suspend(SchoolLifecycleTransitionRequest $request, SchoolLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($this->transition($request, $action, 'suspended'));
    }

    public function reactivate(SchoolLifecycleTransitionRequest $request, SchoolLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($this->transition($request, $action, 'active'));
    }

    public function archive(ArchiveSchoolRequest $request, SchoolLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($this->transition($request, $action, 'archived'));
    }

    /** @return array<string, mixed> */
    private function transition(Request $request, SchoolLifecycleAction $action, string $targetStatus): array
    {
        $actor = $request->user();
        $context = $request->attributes->get('school_lifecycle_context');
        abort_unless($actor instanceof Authenticatable && $context instanceof SchoolLifecycleContext, 401);

        return $action->transition($actor, $context, $targetStatus, $request->string('reason')->toString());
    }
}
