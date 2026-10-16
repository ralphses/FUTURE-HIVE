<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PlatformFeatureFlagAction;
use App\Contexts\Platform\Http\Requests\PutSchoolFeatureFlagRequest;
use App\Contexts\Platform\Http\Requests\StorePlatformFeatureFlagRequest;
use App\Contexts\Platform\Http\Requests\UpdatePlatformFeatureFlagRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformFeatureFlagController
{
    #[Endpoint(title: 'List platform feature flags', description: 'Lists safe versioned, allowlisted platform feature flags. Values never grant permissions or bypass authorization.')]
    public function index(Request $request, PlatformFeatureFlagAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->index($this->identity($request), $request->integer('page', 1), min(100, max(1, $request->integer('per_page', 25)))));
    }

    #[Endpoint(title: 'Create a platform feature flag', description: 'Creates a draft allowlisted platform feature flag. Unknown keys and arbitrary values are rejected.')]
    public function store(StorePlatformFeatureFlagRequest $request, PlatformFeatureFlagAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $request->validated()), 201);
    }

    #[Endpoint(title: 'View a platform feature flag', description: 'Returns a safe public version projection without internal identifiers or private metadata.')]
    public function show(Request $request, string $flag, PlatformFeatureFlagAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $flag));
    }

    #[Endpoint(title: 'Update a draft platform feature flag', description: 'Updates draft metadata or an allowlisted value; active and retired versions are immutable.')]
    public function update(UpdatePlatformFeatureFlagRequest $request, string $flag, PlatformFeatureFlagAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $flag, $request->validated()));
    }

    #[Endpoint(title: 'Change feature flag lifecycle', description: 'Activates or retires a feature flag version with one-active-version protection.')]
    public function lifecycle(Request $request, string $flag, string $transition, PlatformFeatureFlagAction $action): JsonResponse
    {
        abort_unless(in_array($transition, ['activate', 'retire'], true), 404);

        return ApiResponse::data($action->lifecycle($this->identity($request), $flag, $transition));
    }

    #[Endpoint(title: 'Read effective school feature flags', description: 'Returns effective allowlisted values using school override, platform default, then disabled precedence.')]
    public function schoolIndex(Request $request, string $school, PlatformFeatureFlagAction $action): JsonResponse
    {
        return ApiResponse::data($action->schoolFlags($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create a school feature flag override', description: 'Creates a draft school-scoped override for an existing allowlisted platform flag.')]
    public function schoolOverride(PutSchoolFeatureFlagRequest $request, string $school, string $flag, PlatformFeatureFlagAction $action): JsonResponse
    {
        return ApiResponse::data($action->putSchoolOverride($this->identity($request), $school, $flag, $request->validated()), 201);
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
