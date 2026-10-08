<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contexts\Identity\Application\Actions\ResolveSchoolContextAction;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Infrastructure\Authentication\JwtTokenService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class JwtAuthenticate
{
    public function __construct(
        private readonly JwtTokenService $jwt,
        private readonly ResolveSchoolContextAction $resolveContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization');

        if (! str_starts_with($header, 'Bearer ')) {
            throw new AuthenticationException;
        }

        $token = $this->jwt->validate(substr($header, 7));
        $subject = $token->claims()->get('sub');
        $sessionId = $token->claims()->get('sid');

        if (! is_string($subject) || ! is_string($sessionId)) {
            throw new AuthenticationException;
        }

        $identity = UserIdentity::query()->where('public_id', $subject)->first();
        $session = AuthSession::query()->where('public_id', $sessionId)->whereNull('revoked_at')->first();

        if (! $identity instanceof UserIdentity || ! $session instanceof AuthSession || $session->user_id !== $identity->id) {
            throw new AuthenticationException;
        }

        $request->attributes->set('auth_session', $session);
        $request->setUserResolver(static fn (): UserIdentity => $identity);
        $this->resolveContext->handle($identity, $session);

        return $next($request);
    }
}
