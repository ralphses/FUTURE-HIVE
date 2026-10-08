<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Policies;

use App\Contexts\Identity\Application\DTOs\AuthorizationDecision;
use App\Contexts\Identity\Domain\Authorization\FinancialScopeSubject;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class FinancialScopePolicy
{
    public function read(UserIdentity $actor, FinancialScopeSubject $subject): AuthorizationDecision
    {
        if (! $subject->active) {
            return AuthorizationDecision::deny('LIFECYCLE_DENIED');
        }

        return in_array($actor->id, $subject->readIdentityIds, true)
            ? AuthorizationDecision::allow()
            : AuthorizationDecision::deny('OBJECT_SCOPE_DENIED');
    }

    public function manage(UserIdentity $actor, FinancialScopeSubject $subject): AuthorizationDecision
    {
        if (! $subject->active) {
            return AuthorizationDecision::deny('LIFECYCLE_DENIED');
        }

        return in_array($actor->id, $subject->manageIdentityIds, true)
            ? AuthorizationDecision::allow()
            : AuthorizationDecision::deny('OBJECT_SCOPE_DENIED');
    }
}
