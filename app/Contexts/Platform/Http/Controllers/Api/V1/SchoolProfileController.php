<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SchoolProfileAction;
use App\Contexts\Platform\Http\Requests\UpdateSchoolProfileRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolProfileController
{
    /** @response 200 array{data: array<string, mixed>} */
    #[Endpoint(title: 'View school profile', description: 'Returns the trusted school profile and a short-lived signed logo URL when a private logo exists.')]
    public function show(Request $request, string $school, SchoolProfileAction $profile): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Authenticatable, 401);

        return ApiResponse::data($profile->show($actor, $school));
    }

    /** @response 200 array{data: array<string, mixed>} */
    #[Endpoint(title: 'Update school profile', description: 'Updates authorized school contact, address and timezone metadata. It does not change school lifecycle.')]
    public function update(UpdateSchoolProfileRequest $request, string $school, SchoolProfileAction $profile): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Authenticatable, 401);

        return ApiResponse::data($profile->update($actor, $school, $request->validated()));
    }

    /** @response 200 array{data: array<string, mixed>} */
    #[Endpoint(title: 'Upload school logo', description: 'Scans and stores an authenticated private Cloudinary logo under the trusted school namespace.')]
    public function uploadLogo(Request $request, string $school, SchoolProfileAction $profile): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Authenticatable, 401);
        $request->validate(['logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        return ApiResponse::data($profile->uploadLogo($actor, $school, $request->file('logo')));
    }

    /** @response 200 array{data: array<string, mixed>} */
    #[Endpoint(title: 'Remove school logo', description: 'Removes the current logo reference without exposing or deleting public media.')]
    public function removeLogo(Request $request, string $school, SchoolProfileAction $profile): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Authenticatable, 401);

        return ApiResponse::data($profile->removeLogo($actor, $school));
    }
}
