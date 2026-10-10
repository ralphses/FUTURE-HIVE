<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\GuardianRelationshipAction;
use App\Contexts\Registry\Application\Actions\StudentGuardianAuditTrailAction;
use App\Contexts\Registry\Http\Requests\CreateGuardianRelationshipRequest;
use App\Contexts\Registry\Http\Requests\RevokeGuardianRelationshipRequest;
use App\Contexts\Registry\Http\Requests\UpdateGuardianRelationshipRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GuardianRelationshipController
{
    #[Endpoint(title: 'List school guardians', description: 'Lists guardian profiles already linked to students in the selected school. It does not create identities or activate guardian access.')]
    public function guardians(Request $request, string $school, GuardianRelationshipAction $action): JsonResponse
    {
        return ApiResponse::data($action->guardians($this->identity($request), $school));
    }

    #[Endpoint(title: 'View a guardian profile', description: 'Returns the bounded display information for a guardian linked to the selected school. Contacts and credentials are never returned.')]
    public function showGuardian(Request $request, string $school, string $guardian, GuardianRelationshipAction $action): JsonResponse
    {
        return ApiResponse::data($action->showGuardian($this->identity($request), $school, $guardian));
    }

    #[Endpoint(operationId: 'v1.schools.guardians.audit-history', title: 'View guardian audit history', description: 'Lists safe, read-only history for guardian relationship and invitation changes in the selected school. The trusted school context controls visibility.')]
    #[Response(status: 200, description: 'A paginated safe audit history response.', type: 'array')]
    public function auditHistory(Request $request, string $school, string $guardian, StudentGuardianAuditTrailAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->forGuardian($this->identity($request), $school, $guardian));
    }

    #[Endpoint(title: 'List a student’s guardian relationships', description: 'Lists pending or active guardian relationships for one student in the selected school. Pending relationships do not grant access.')]
    public function studentRelationships(Request $request, string $school, string $student, GuardianRelationshipAction $action): JsonResponse
    {
        return ApiResponse::data($action->forStudent($this->identity($request), $school, $student));
    }

    #[Endpoint(title: 'Add a guardian relationship', description: 'Links an existing IAM identity to a student as a pending relationship. It does not send an invitation, verify the relationship or grant access.')]
    public function create(CreateGuardianRelationshipRequest $request, string $school, string $student, GuardianRelationshipAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $student, $request->validated()), 201);
    }

    #[Endpoint(title: 'Update a guardian relationship', description: 'Updates the relationship type or safe display metadata. Verification and lifecycle status remain server-controlled.')]
    public function update(UpdateGuardianRelationshipRequest $request, string $school, string $student, string $relationship, GuardianRelationshipAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $student, $relationship, $request->validated()));
    }

    #[Endpoint(title: 'Revoke a guardian relationship', description: 'Revokes the relationship and retains its history. It does not delete the guardian profile or identity.')]
    public function revoke(RevokeGuardianRelationshipRequest $request, string $school, string $student, string $relationship, GuardianRelationshipAction $action): JsonResponse
    {
        return ApiResponse::data($action->revoke($this->identity($request), $school, $student, $relationship, (string) $request->validated('reason')));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
