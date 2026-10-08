<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\SchoolLifecycleContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSchoolLifecycleContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->user();
        $session = $request->attributes->get('auth_session');

        if (! $identity instanceof UserIdentity || ! $session instanceof AuthSession) {
            throw new AuthorizationException;
        }

        $membershipId = $session->getAttribute('active_school_membership_id');
        if (! is_int($membershipId) && ! is_numeric($membershipId)) {
            throw new AuthorizationException;
        }

        $membership = SchoolMembership::query()
            ->with('school')
            ->whereKey((int) $membershipId)
            ->where('user_id', $identity->id)
            ->where('status', 'active')
            ->whereHas('school', static fn ($query) => $query->whereIn('status', ['active', 'suspended']))
            ->first();

        if (! $membership instanceof SchoolMembership) {
            throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
        }

        $routeSchool = $request->route('school');
        if (! is_string($routeSchool) || $routeSchool !== $membership->school->public_id) {
            throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
        }

        $request->attributes->set('school_lifecycle_context', new SchoolLifecycleContext(
            schoolId: (int) $membership->school_id,
            schoolPublicId: (string) $membership->school->public_id,
            membershipId: (int) $membership->id,
            identityId: (int) $membership->user_id,
            schoolStatus: (string) $membership->school->status,
        ));

        return $next($request);
    }
}
