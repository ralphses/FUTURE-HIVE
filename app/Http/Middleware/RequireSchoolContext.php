<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contexts\Identity\Application\Actions\ResolveSchoolContextAction;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSchoolContext
{
    public function __construct(private readonly ResolveSchoolContextAction $resolveContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->user();
        $session = $request->attributes->get('auth_session');
        $hadSelectedMembership = $session instanceof AuthSession
            && $session->active_school_membership_id !== null;

        $context = $identity instanceof UserIdentity && $session instanceof AuthSession
            ? $this->resolveContext->handle($identity, $session)
            : null;

        if ($context === null) {
            if ($hadSelectedMembership) {
                throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
            }

            throw new AuthorizationException;
        }

        $routeSchool = $request->route('school');
        if (is_string($routeSchool) && $routeSchool !== $context->membership->school->public_id) {
            throw (new ModelNotFoundException)->setModel(SchoolMembership::class);
        }

        return $next($request);
    }
}
