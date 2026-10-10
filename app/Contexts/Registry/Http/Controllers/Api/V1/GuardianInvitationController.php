<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\GuardianInvitationAction;
use App\Contexts\Registry\Http\Requests\ConfirmGuardianInvitationRequest;
use App\Contexts\Registry\Http\Requests\RequestGuardianInvitationRequest;
use App\Contexts\Registry\Http\Requests\RevokeGuardianInvitationRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GuardianInvitationController
{
    #[Endpoint(title: 'Request guardian access', description: 'Creates a short-lived invitation for a pending student–guardian relationship. The relationship stays pending until the invited identity confirms the code.')]
    public function request(RequestGuardianInvitationRequest $request, string $school, string $student, string $relationship, GuardianInvitationAction $action): JsonResponse
    {
        return ApiResponse::data($action->request($this->identity($request), $school, $student, $relationship, $request->ip(), $request->userAgent()), 202);
    }

    #[Endpoint(title: 'Confirm guardian access', description: 'Confirms the invitation code for the signed-in invited guardian. Only the bound pending relationship becomes active; no school membership or student JWT claims are created.')]
    #[BodyParameter(name: 'code', description: 'The six-digit code delivered through the configured guardian-invitation channel.', type: 'string', required: true, example: '123456')]
    public function confirm(ConfirmGuardianInvitationRequest $request, string $invitation, GuardianInvitationAction $action): JsonResponse
    {
        return ApiResponse::data($action->confirm($this->identity($request), $invitation, (string) $request->validated('code')));
    }

    #[Endpoint(title: 'List my active student links', description: 'Lists active, verified student relationships for the signed-in guardian in the selected school. Pending and revoked relationships are excluded.')]
    public function links(Request $request, GuardianInvitationAction $action): JsonResponse
    {
        return ApiResponse::data($action->activeLinks($this->identity($request)));
    }

    #[Endpoint(title: 'Revoke guardian invitation', description: 'Revokes an unused guardian invitation while retaining its history. It does not delete the guardian identity or relationship.')]
    #[BodyParameter(name: 'reason', description: 'Short administrative reason for revoking the invitation.', type: 'string', required: true, example: 'Fictional administrative change')]
    public function revoke(RevokeGuardianInvitationRequest $request, string $school, string $invitation, GuardianInvitationAction $action): JsonResponse
    {
        return ApiResponse::data($action->revoke($this->identity($request), $school, $invitation, (string) $request->validated('reason')));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
