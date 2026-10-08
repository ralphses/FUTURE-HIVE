<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RefreshCookieCsrf
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookieToken = $request->cookie('XSRF-TOKEN');
        $refreshCookie = $request->cookie('refresh_token');

        if ($request->string('client')->toString() === 'browser' && $refreshCookie === null) {
            abort(419, 'The CSRF token is invalid.');
        }

        if ($refreshCookie !== null && ($cookieToken === null || ! hash_equals($cookieToken, (string) $request->header('X-XSRF-TOKEN')))) {
            abort(419, 'The CSRF token is invalid.');
        }

        return $next($request);
    }
}
