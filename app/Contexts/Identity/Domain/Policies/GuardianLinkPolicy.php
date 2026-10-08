<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Policies;

use App\Contexts\Identity\Application\DTOs\AuthorizationDecision;
use App\Contexts\Identity\Domain\Authorization\GuardianLinkSubject;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class GuardianLinkPolicy
{
    public function view(UserIdentity $actor, GuardianLinkSubject $subject): AuthorizationDecision
    {
        if ($subject->status !== 'active' || ! $subject->verified || ! $subject->effective) {
            return AuthorizationDecision::deny('RELATIONSHIP_UNVERIFIED');
        }

        return $subject->guardianIdentityId === $actor->id
            ? AuthorizationDecision::allow()
            : AuthorizationDecision::deny('OBJECT_SCOPE_DENIED');
    }
}
