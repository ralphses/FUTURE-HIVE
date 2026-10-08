<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\AuthorizationDecision;
use App\Contexts\Identity\Domain\Models\BreakGlassAccessGrant;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class AuthorizeBreakGlassAccessAction
{
    public function __construct(private readonly ResolvePlatformPermissionsAction $permissions) {}

    public function handle(UserIdentity $identity, int $schoolId, string $resourceScope): AuthorizationDecision
    {
        if ($schoolId < 1 || trim($resourceScope) === '') {
            return AuthorizationDecision::deny('GRANT_SCOPE_DENIED');
        }

        if (! $this->permissions->allows($identity, 'platform.support.access')) {
            return AuthorizationDecision::deny('MISSING_PLATFORM_PERMISSION');
        }

        $activeGrant = BreakGlassAccessGrant::query()
            ->where('granted_to_user_id', $identity->id)
            ->where('school_id', $schoolId)
            ->where('resource_scope', trim($resourceScope))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        return $activeGrant
            ? AuthorizationDecision::allow()
            : AuthorizationDecision::deny('GRANT_SCOPE_DENIED');
    }
}
