<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Policies;

use App\Contexts\Identity\Application\DTOs\AuthorizationDecision;
use App\Contexts\Identity\Domain\Authorization\ClassAssignmentSubject;
use App\Contexts\Identity\Domain\Models\UserIdentity;

final class ClassAssignmentPolicy
{
    public function view(UserIdentity $actor, ClassAssignmentSubject $subject): AuthorizationDecision
    {
        if ($subject->status !== 'active' || ! $subject->visible) {
            return AuthorizationDecision::deny('LIFECYCLE_DENIED');
        }

        if ($subject->assignedIdentityId !== $actor->id) {
            return AuthorizationDecision::deny('OBJECT_SCOPE_DENIED');
        }

        return AuthorizationDecision::allow();
    }

    public function manage(UserIdentity $actor, ClassAssignmentSubject $subject): AuthorizationDecision
    {
        $view = $this->view($actor, $subject);

        if (! $view->allowed) {
            return $view;
        }

        return $subject->editable
            ? AuthorizationDecision::allow()
            : AuthorizationDecision::deny('LIFECYCLE_DENIED');
    }
}
