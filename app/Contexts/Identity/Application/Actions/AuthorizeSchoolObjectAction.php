<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\AuthorizationDecision;
use App\Contexts\Identity\Domain\Authorization\ClassAssignmentSubject;
use App\Contexts\Identity\Domain\Authorization\FinancialScopeSubject;
use App\Contexts\Identity\Domain\Authorization\GuardianLinkSubject;
use App\Contexts\Identity\Domain\Authorization\SchoolAuthorizationSubject;
use App\Contexts\Identity\Domain\Authorization\StudentSelfSubject;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Policies\ClassAssignmentPolicy;
use App\Contexts\Identity\Domain\Policies\FinancialScopePolicy;
use App\Contexts\Identity\Domain\Policies\GuardianLinkPolicy;
use App\Contexts\Identity\Domain\Policies\StudentSelfPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AuthorizeSchoolObjectAction
{
    public function __construct(
        private readonly ResolveSchoolPermissionsAction $permissions,
        private readonly ClassAssignmentPolicy $classAssignments,
        private readonly GuardianLinkPolicy $guardianLinks,
        private readonly StudentSelfPolicy $students,
        private readonly FinancialScopePolicy $finances,
    ) {}

    public function handle(UserIdentity $actor, string $permission, SchoolAuthorizationSubject $subject): AuthorizationDecision
    {
        if ($subject->schoolPublicId() === '') {
            return AuthorizationDecision::deny('CROSS_SCHOOL');
        }

        try {
            $hasPermission = $this->permissions->allows($actor, $subject->schoolPublicId(), $permission);
        } catch (ModelNotFoundException) {
            return AuthorizationDecision::deny('INACTIVE_MEMBERSHIP');
        }

        if (! $hasPermission) {
            return AuthorizationDecision::deny('MISSING_PERMISSION');
        }

        return match (true) {
            $subject instanceof ClassAssignmentSubject => $this->authorizeClassAssignment($actor, $permission, $subject),
            $subject instanceof GuardianLinkSubject => $this->guardianLinks->view($actor, $subject),
            $subject instanceof StudentSelfSubject => $this->students->view($actor, $subject),
            $subject instanceof FinancialScopeSubject => $this->authorizeFinance($actor, $permission, $subject),
            default => AuthorizationDecision::deny('OBJECT_SCOPE_DENIED'),
        };
    }

    private function authorizeClassAssignment(UserIdentity $actor, string $permission, ClassAssignmentSubject $subject): AuthorizationDecision
    {
        return $permission === 'academic.assignments.manage'
            ? $this->classAssignments->manage($actor, $subject)
            : $this->classAssignments->view($actor, $subject);
    }

    private function authorizeFinance(UserIdentity $actor, string $permission, FinancialScopeSubject $subject): AuthorizationDecision
    {
        return $permission === 'finance.records.manage'
            ? $this->finances->manage($actor, $subject)
            : $this->finances->read($actor, $subject);
    }
}
