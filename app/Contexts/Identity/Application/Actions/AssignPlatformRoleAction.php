<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssignPlatformRoleAction
{
    public function __construct(
        private readonly ResolvePlatformPermissionsAction $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    public function handle(UserIdentity $actor, UserIdentity $target, string $roleKey, string $reason): PlatformRoleAssignment
    {
        if (! $this->permissions->allows($actor, 'platform.roles.manage')) {
            $this->notFound();
        }

        $role = Role::query()->where('key', $roleKey)->where('scope', 'platform')->where('is_active', true)->first();
        if (! $role instanceof Role || trim($reason) === '') {
            throw ValidationException::withMessages(['role' => 'The platform role assignment is invalid.']);
        }

        return DB::transaction(function () use ($actor, $target, $role, $reason): PlatformRoleAssignment {
            $assignment = PlatformRoleAssignment::query()->firstOrCreate(
                ['user_id' => $target->id, 'role_id' => $role->id, 'revoked_at' => null],
                ['assigned_by' => $actor->id, 'assigned_at' => now()],
            );

            $this->audit->execute(new AuditEventData(
                action: 'platform.role_assigned',
                subjectType: PlatformRoleAssignment::class,
                subjectPublicId: $assignment->public_id,
                actorId: $actor->id,
                reason: $reason,
                authorizationContext: ['permission' => 'platform.roles.manage', 'role' => $role->key],
            ));

            return $assignment;
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(PlatformRoleAssignment::class);
    }
}
