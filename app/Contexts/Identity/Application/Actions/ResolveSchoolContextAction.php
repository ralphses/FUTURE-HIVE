<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Application\DTOs\SchoolContext;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;

final class ResolveSchoolContextAction
{
    public function handle(UserIdentity $identity, AuthSession $session): ?SchoolContext
    {
        $membershipId = $session->getAttribute('active_school_membership_id');

        if (! is_int($membershipId) && ! is_numeric($membershipId)) {
            Context::forget('school_id');
            Context::forget('school_membership_id');
            Context::forgetHidden('school_context');
            Context::forgetHidden('tenant_context');

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
            $suspendedMembership = SchoolMembership::query()
                ->whereKey((int) $membershipId)
                ->where('user_id', $identity->id)
                ->where('status', 'active')
                ->whereHas('school', static fn ($query) => $query->where('status', 'suspended'))
                ->exists();

            if (! $suspendedMembership) {
                $session->newQuery()->whereKey($session->id)->update(['active_school_membership_id' => null]);
            }
            Context::forget('school_id');
            Context::forget('school_membership_id');
            Context::forgetHidden('school_context');
            Context::forgetHidden('tenant_context');

            return null;
        }

        $context = new SchoolContext($membership);
        Context::add('school_id', $membership->school->public_id);
        Context::add('school_membership_id', $membership->public_id);
        Context::addHidden('school_context', $context);
        Context::addHidden('tenant_context', TenantContext::fromSchoolContext($context));

        return $context;
    }
}
