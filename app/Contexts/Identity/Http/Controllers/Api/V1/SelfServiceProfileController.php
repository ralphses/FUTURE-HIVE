<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\SelfServiceProfileAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Contexts\Identity\Http\Requests\UpdateSelfProfileRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SelfServiceProfileController
{
    #[Endpoint(title: 'Show my profile', description: 'Shows safe profile metadata for the authenticated identity without exposing contact values or private records.')]
    #[Response(status: 200, description: 'Safe authenticated profile projection.', type: 'array')]
    public function show(Request $request, SelfServiceProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->profile($this->identity($request)));
    }

    #[Endpoint(title: 'Update my profile', description: 'Updates bounded profile fields for the authenticated identity. Contact verification remains a separate workflow.')]
    #[Response(status: 200, description: 'Updated safe authenticated profile projection.', type: 'array')]
    public function update(UpdateSelfProfileRequest $request, SelfServiceProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateProfile($this->identity($request), $request->validated()));
    }

    #[Endpoint(title: 'Show my notification preferences', description: 'Shows server-controlled notification preference values for the authenticated identity.')]
    #[Response(status: 200, description: 'Notification preference projection.', type: 'array')]
    public function preferences(Request $request, SelfServiceProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->preferences($this->identity($request)));
    }

    #[Endpoint(title: 'Update my notification preferences', description: 'Replaces the authenticated identity notification preferences without sending notifications or changing access.')]
    #[Response(status: 200, description: 'Updated notification preference projection.', type: 'array')]
    public function updatePreferences(UpdateNotificationPreferencesRequest $request, SelfServiceProfileAction $action): JsonResponse
    {
        return ApiResponse::data($action->updatePreferences($this->identity($request), $request->validated()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
