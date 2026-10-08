<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\BreakGlassAccessGrant;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RevokeBreakGlassGrantAction
{
    public function __construct(
        private readonly ResolvePlatformPermissionsAction $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    public function handle(UserIdentity $actor, string $grantPublicId, string $reason): void
    {
        if (! $this->permissions->allows($actor, 'platform.grants.revoke')) {
            $this->notFound();
        }

        $grant = BreakGlassAccessGrant::query()->where('public_id', $grantPublicId)->whereNull('revoked_at')->first();
        if (! $grant instanceof BreakGlassAccessGrant) {
            $this->notFound();
        }

        DB::transaction(function () use ($actor, $grant, $reason): void {
            $grant->update(['revoked_at' => now(), 'revoked_reason' => trim($reason) ?: 'revoked']);
            $this->audit->execute(new AuditEventData(
                action: 'platform.break_glass_grant_revoked',
                subjectType: BreakGlassAccessGrant::class,
                subjectPublicId: $grant->public_id,
                schoolId: $grant->school_id,
                actorId: $actor->id,
                reason: trim($reason) ?: 'revoked',
                authorizationContext: ['permission' => 'platform.grants.revoke'],
                requiresSchoolContext: $grant->school_id !== null,
            ));
        });
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(BreakGlassAccessGrant::class);
    }
}
