<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssignSchoolRolesAction
{
    public function __construct(
        private readonly ResolveSchoolPermissionsAction $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /**
     * @param  list<string>  $roleKeys
     * @return Collection<int, MembershipRole>
     */
    public function handle(UserIdentity $actor, string $schoolPublicId, string $membershipPublicId, array $roleKeys): Collection
    {
        if (! $this->permissions->allows($actor, $schoolPublicId, 'school.roles.assign')) {
            $this->notFound();
        }

        $membership = SchoolMembership::query()
            ->where('public_id', $membershipPublicId)
            ->where('status', 'active')
            ->whereHas('school', fn ($query) => $query->where('public_id', $schoolPublicId)->where('status', 'active'))
            ->first();

        if (! $membership instanceof SchoolMembership) {
            $this->notFound();
        }

        $roles = Role::query()->whereIn('key', array_values(array_unique($roleKeys)))->where('scope', 'school')->where('is_active', true)->get()->keyBy('key');
        if ($roles->count() !== count(array_unique($roleKeys))) {
            throw ValidationException::withMessages(['roles' => 'One or more roles are unavailable.']);
        }

        DB::transaction(function () use ($actor, $membership, $roles): void {
            foreach ($roles as $role) {
                $alreadyAssigned = MembershipRole::query()->where('school_membership_id', $membership->id)->where('role_id', $role->id)->whereNull('revoked_at')->exists();
                if ($alreadyAssigned) {
                    continue;
                }

                $assignment = MembershipRole::query()->create([
                    'school_membership_id' => $membership->id,
                    'role_id' => $role->id,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
                $this->audit->execute(new AuditEventData(
                    action: 'school.role_assigned',
                    subjectType: MembershipRole::class,
                    subjectPublicId: $assignment->public_id,
                    schoolId: $membership->school_id,
                    actorId: $actor->id,
                    authorizationContext: ['permission' => 'school.roles.assign'],
                    stateTransition: ['role' => $role->key, 'membership' => $membership->public_id],
                    requiresSchoolContext: true,
                ));
            }
        });

        return MembershipRole::query()->where('school_membership_id', $membership->id)->whereNull('revoked_at')->with('role')->get();
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
    }
}
