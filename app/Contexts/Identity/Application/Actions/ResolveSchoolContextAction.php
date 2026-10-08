<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\SchoolContext;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Support\Facades\Context;

final class ResolveSchoolContextAction
{
    public function handle(UserIdentity $identity, AuthSession $session): ?SchoolContext
    {
        $membershipId = $session->getAttribute('active_school_membership_id');

        if (! is_int($membershipId) && ! is_numeric($membershipId)) {
            return null;
        }

        $membership = SchoolMembership::query()
            ->with('school')
            ->whereKey((int) $membershipId)
            ->where('user_id', $identity->id)
            ->where('status', 'active')
            ->whereHas('school', static fn ($query) => $query->where('status', 'active'))
            ->first();

        if (! $membership instanceof SchoolMembership) {
            $session->newQuery()->whereKey($session->id)->update(['active_school_membership_id' => null]);

            return null;
        }

        $context = new SchoolContext($membership);
        Context::add('school_id', $membership->school->public_id);
        Context::add('school_membership_id', $membership->public_id);

        return $context;
    }
}
