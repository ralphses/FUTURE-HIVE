<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RevokeSchoolRoleAction
{
    public function __construct(
        private readonly ResolveSchoolPermissionsAction $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    public function handle(UserIdentity $actor, string $schoolPublicId, string $membershipPublicId, string $rolePublicId): void
    {
        if (! $this->permissions->allows($actor, $schoolPublicId, 'school.roles.revoke')) {
            $this->notFound();
        }

        DB::transaction(function () use ($actor, $schoolPublicId, $membershipPublicId, $rolePublicId): void {
            $assignment = MembershipRole::query()
                ->where('public_id', $rolePublicId)
                ->whereNull('revoked_at')
                ->whereHas('membership', function ($query) use ($membershipPublicId, $schoolPublicId): void {
                    $query->where('public_id', $membershipPublicId)
                        ->where('status', 'active')
                        ->whereHas('school', fn ($schoolQuery) => $schoolQuery->where('public_id', $schoolPublicId));
                })
                ->with('membership', 'role')
                ->lockForUpdate()
                ->first();
            if (! $assignment instanceof MembershipRole) {
                $this->notFound();
            }

            $assignment->update(['revoked_at' => now(), 'revoked_reason' => 'role_revoked']);
            $this->audit->execute(new AuditEventData(
                action: 'school.role_revoked',
                subjectType: MembershipRole::class,
                subjectPublicId: $assignment->public_id,
                schoolId: $assignment->membership->school_id,
                actorId: $actor->id,
                authorizationContext: ['permission' => 'school.roles.revoke'],
                stateTransition: ['role' => $assignment->role->key, 'membership' => $assignment->membership->public_id],
                requiresSchoolContext: true,
            ));
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
    }
}
