<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Infrastructure\Authentication;

use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Services\AuthenticationFailed;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Throwable;

final class JwtTokenService
{
    public function issue(UserIdentity $identity, AuthSession $session): string
    {
        $privateKey = config('auth.jwt.private_key');

        if (! is_string($privateKey) || $privateKey === '') {
            throw new AuthenticationFailed;
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiration = $now->add(new DateInterval('PT'.(int) config('auth.jwt.access_ttl', 600).'S'));
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($privateKey),
            InMemory::plainText($privateKey),
        );

        return $configuration->builder()
            ->issuedBy((string) config('auth.jwt.issuer'))
            ->permittedFor((string) config('auth.jwt.audience'))
            ->identifiedBy((string) Str::uuid7())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiration)
            ->withHeader('kid', (string) config('auth.jwt.current_kid'))
            ->relatedTo($identity->public_id)
            ->withClaim('sid', $session->public_id)
            ->withHeader('typ', 'access')
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    public function validate(string $value): Plain
    {
        try {
            $configuration = $this->configurationFor($value);
            $token = $configuration->parser()->parse($value);

            if (! $token instanceof Plain || $token->headers()->get('alg') !== 'RS256' || $token->headers()->get('typ') !== 'access') {
                throw new AuthenticationFailed;
            }

            $configuration->validator()->assert(
                $token,
                new SignedWith(new Sha256, $configuration->verificationKey()),
                new StrictValidAt(new UtcClock),
                new IssuedBy((string) config('auth.jwt.issuer')),
                new PermittedFor((string) config('auth.jwt.audience')),
            );

            return $token;
        } catch (Throwable $exception) {
            if ($exception instanceof AuthenticationFailed) {
                throw $exception;
            }

            throw new AuthenticationFailed;
        }
    }

    private function configurationFor(string $value): Configuration
    {
        $parser = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText('verification-only'),
            InMemory::plainText('verification-only'),
        )->parser();
        $token = $parser->parse($value);
        $kid = $token->headers()->get('kid');
        $keys = config('auth.jwt.public_keys', []);
        $key = is_string($kid) && is_array($keys) ? ($keys[$kid] ?? null) : null;

        if (! is_string($key) || $key === '') {
            throw new AuthenticationFailed;
        }

        return Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText('verification-only'),
            InMemory::plainText($key),
        );
    }
}
