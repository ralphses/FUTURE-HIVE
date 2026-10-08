<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\AssignSchoolRolesAction;
use App\Contexts\Identity\Application\Actions\ListMembershipRolesAction;
use App\Contexts\Identity\Application\Actions\ListSchoolRolesAction;
use App\Contexts\Identity\Application\Actions\ResolveSchoolPermissionsAction;
use App\Contexts\Identity\Application\Actions\RevokeSchoolRoleAction;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Http\Requests\AssignSchoolRolesRequest;
use App\Http\Controllers\Controller;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolRoleController extends Controller
{
    /** @response array{data: array<int, array{public_id: string, key: string, label: string, description: string, permissions: array<int, string>}>} */
    public function catalogue(Request $request, string $school, ListSchoolRolesAction $roles): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return response()->json(['data' => $roles->handle($identity, $school)->map(fn (Role $role): array => [
            'public_id' => $role->public_id,
            'key' => $role->key,
            'label' => $role->label,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('key')->values()->all(),
        ])->values()->all()]);
    }

    /** @response array{data: array<int, array{assignment_id: string, role: array{public_id: string, key: string, label: string}, assigned_at: string|null}>} */
    public function membership(Request $request, string $school, string $membership, ListMembershipRolesAction $roles): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return response()->json(['data' => $roles->handle($identity, $school, $membership)->map(function (MembershipRole $assignment): array {
            $assignedAt = $assignment->getAttribute('assigned_at');

            return [
                'assignment_id' => $assignment->public_id,
                'role' => [
                    'public_id' => $assignment->role->public_id,
                    'key' => $assignment->role->key,
                    'label' => $assignment->role->label,
                ],
                'assigned_at' => $assignedAt instanceof CarbonInterface ? $assignedAt->toISOString() : null,
            ];
        })->values()->all()]);
    }

    /** @response array{data: array{assignments: array<int, array{assignment_id: string, role: string}>}} */
    public function assign(AssignSchoolRolesRequest $request, string $school, string $membership, AssignSchoolRolesAction $assign): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);
        $assignments = $assign->handle($identity, $school, $membership, $request->validated('roles'));

        return response()->json(['data' => ['assignments' => $assignments->map(fn (MembershipRole $assignment): array => [
            'assignment_id' => $assignment->public_id,
            'role' => $assignment->role->key,
        ])->values()->all()]], 201);
    }

    /** @response array{data: array{revoked: bool}} */
    public function revoke(Request $request, string $school, string $membership, string $role, RevokeSchoolRoleAction $revoke): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);
        $revoke->handle($identity, $school, $membership, $role);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** @response array{data: array{permissions: array<int, string>}} */
    public function permissions(Request $request, string $school, ResolveSchoolPermissionsAction $permissions): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return response()->json(['data' => ['permissions' => $permissions->handle($identity, $school)->pluck('key')->values()->all()]]);
    }
}
