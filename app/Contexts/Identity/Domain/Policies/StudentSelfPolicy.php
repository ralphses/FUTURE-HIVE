<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Policies;

use App\Contexts\Identity\Application\DTOs\AuthorizationDecision;
use App\Contexts\Identity\Domain\Authorization\StudentSelfSubject;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class StudentSelfPolicy
{
    public function view(UserIdentity $actor, StudentSelfSubject $subject): AuthorizationDecision
    {
        if (! $subject->published) {
            return AuthorizationDecision::deny('LIFECYCLE_DENIED');
        }

        if ($subject->studentIdentityId === $actor->id) {
            return AuthorizationDecision::allow();
        }

        if ($subject->guardianAccessAllowed && in_array($actor->id, $subject->verifiedGuardianIdentityIds, true)) {
            return AuthorizationDecision::allow();
        }

        return AuthorizationDecision::deny('OBJECT_SCOPE_DENIED');
    }
}
