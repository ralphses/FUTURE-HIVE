<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RevokePlatformRoleAction
{
    public function __construct(
        private readonly ResolvePlatformPermissionsAction $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    public function handle(UserIdentity $actor, string $assignmentPublicId, string $reason): void
    {
        if (! $this->permissions->allows($actor, 'platform.roles.manage')) {
            $this->notFound();
        }

        $assignment = PlatformRoleAssignment::query()
            ->where('public_id', $assignmentPublicId)
            ->whereNull('revoked_at')
            ->whereHas('role', fn ($query) => $query->where('scope', 'platform'))
            ->first();

        if (! $assignment instanceof PlatformRoleAssignment) {
            $this->notFound();
        }

        DB::transaction(function () use ($actor, $assignment, $reason): void {
            $assignment->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'revoked_reason' => trim($reason) ?: 'revoked']);
            $this->audit->execute(new AuditEventData(
                action: 'platform.role_revoked',
                subjectType: PlatformRoleAssignment::class,
                subjectPublicId: $assignment->public_id,
                actorId: $actor->id,
                reason: trim($reason) ?: 'revoked',
                authorizationContext: ['permission' => 'platform.roles.manage'],
            ));
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(PlatformRoleAssignment::class);
    }
}
