<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\AcceptSchoolInvitationAction;
use App\Contexts\Identity\Application\Actions\CreateSchoolInvitationAction;
use App\Contexts\Identity\Application\Actions\RevokeSchoolInvitationAction;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Http\Requests\AcceptSchoolInvitationRequest;
use App\Contexts\Identity\Http\Requests\CreateSchoolInvitationRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class SchoolInvitationController
{
    /**
     * @response 201 array{data: array{invitation_id: string, token: string, expires_at: string}}
     */
    public function create(
        CreateSchoolInvitationRequest $request,
        string $school,
        CreateSchoolInvitationAction $invitations,
    ): JsonResponse {
        $identity = $request->user();

        abort_unless($identity instanceof UserIdentity, 401);

        $issue = $invitations->handle(
            $identity,
            $school,
            $request->string('contact')->toString(),
        );

        $expiresAt = $issue->invitation->getAttribute('expires_at');

        return ApiResponse::data([
            'invitation_id' => $issue->invitation->public_id,
            'token' => $issue->token,
            'expires_at' => $expiresAt instanceof Carbon ? $expiresAt->toISOString() : null,
        ], 201);
    }

    /**
     * @response array{data: array{membership_id: string, school_id: string}}
     */
    public function accept(
        AcceptSchoolInvitationRequest $request,
        string $invitation,
        AcceptSchoolInvitationAction $accept,
    ): JsonResponse {
        $identity = $request->user();

        abort_unless($identity instanceof UserIdentity, 401);

        $membership = $accept->handle(
            $identity,
            $invitation,
            $request->string('token')->toString(),
        );

        return ApiResponse::data([
            'membership_id' => $membership->public_id,
            'school_id' => $membership->school->public_id,
        ]);
    }

    /**
     * @response array{data: array{revoked: bool}}
     */
    public function revoke(
        string $invitation,
        RevokeSchoolInvitationAction $revoke,
    ): JsonResponse {
        $identity = request()->user();

        abort_unless($identity instanceof UserIdentity, 401);

        $revoke->handle($identity, $invitation);

        return ApiResponse::data(['revoked' => true]);
    }
}
