<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\AuthenticateIdentityAction;
use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Http\Requests\LoginRequest;
use App\Contexts\Identity\Http\Requests\RefreshTokenRequest;
use App\Support\Http\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class AuthenticationController
{
    /**
     * @response array{data: array{access_token: string, token_type: string, expires_in: int, session_id: string, refresh_token?: string}}
     */
    public function login(LoginRequest $request, AuthenticateIdentityAction $authenticate): JsonResponse
    {
        $client = $request->string('client', 'api')->toString();
        $result = $authenticate->login(
            $request->string('login')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        $response = ApiResponse::data($result->toArray($client !== 'browser'));

        if ($client === 'browser') {
            $response->withCookie($this->refreshCookie($result->refreshToken));
            $response->withCookie($this->csrfCookie());
        }

        return $response;
    }

    /**
     * @response array{data: array{access_token: string, token_type: string, expires_in: int, session_id: string, refresh_token?: string}}
     */
    public function refresh(RefreshTokenRequest $request, AuthenticateIdentityAction $authenticate): JsonResponse
    {
        $client = $request->string('client', 'api')->toString();
        $token = $request->string('refresh_token')->toString() ?: (string) $request->cookie('refresh_token');
        $result = $authenticate->refresh($token, $request->ip(), $request->userAgent());
        $response = ApiResponse::data($result->toArray($client !== 'browser'));

        if ($client === 'browser' || $request->cookie('refresh_token') !== null) {
            $response->withCookie($this->refreshCookie($result->refreshToken));
        }

        return $response;
    }

    public function logout(Request $request, AuthenticateIdentityAction $authenticate): JsonResponse
    {
        $session = $request->attributes->get('auth_session');

        if ($session instanceof AuthSession) {
            $authenticate->revoke($session);
        }

        return ApiResponse::data(['logged_out' => true]);
    }

    public function logoutAll(Request $request, AuthenticateIdentityAction $authenticate): JsonResponse
    {
        $identity = $request->user();

        if ($identity instanceof UserIdentity) {
            $authenticate->revokeAll($identity);
        }

        return ApiResponse::data(['logged_out' => true]);
    }

    /**
     * @response array{data: array{public_id: string, name: string, contacts: array<int, array{type: string, value: string, verified_at: string|null, is_primary: bool}>}}
     */
    public function me(Request $request): JsonResponse
    {
        /** @var UserIdentity $identity */
        $identity = $request->user();

        return ApiResponse::data([
            'public_id' => $identity->public_id,
            'name' => $identity->name,
            'contacts' => $identity->activeContacts()->get()->map(static function (UserContact $contact): array {
                $type = $contact->getAttribute('type');
                $verifiedAt = $contact->getAttribute('verified_at');

                return [
                    'type' => $type instanceof ContactType ? $type->value : (string) $type,
                    'value' => (string) $contact->getAttribute('canonical_value'),
                    'verified_at' => $verifiedAt instanceof CarbonInterface ? $verifiedAt->toISOString() : null,
                    'is_primary' => (bool) $contact->getAttribute('is_primary'),
                ];
            })->values()->all(),
        ]);
    }

    private function refreshCookie(string $token): Cookie
    {
        return cookie('refresh_token', $token, 20160, '/', null, true, true, false, 'lax');
    }

    private function csrfCookie(): Cookie
    {
        return cookie('XSRF-TOKEN', Str::random(40), 10, '/', null, app()->environment('production'), false, false, 'lax');
    }
}
