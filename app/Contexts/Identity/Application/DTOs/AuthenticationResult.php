<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\DTOs;

use App\Contexts\Identity\Domain\Models\AuthSession;

final readonly class AuthenticationResult
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public AuthSession $session,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, session_id: string, refresh_token?: string}
     */
    public function toArray(bool $includeRefreshToken = true): array
    {
        $result = [
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('auth.jwt.access_ttl', 600),
            'session_id' => $this->session->public_id,
        ];

        if ($includeRefreshToken) {
            $result['refresh_token'] = $this->refreshToken;
        }

        return $result;
    }
}
