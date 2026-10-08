<?php

declare(strict_types=1);

namespace App\Support\Observability;

final class SensitiveDataRedactor
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'address',
        'api_key',
        'api_secret',
        'authorization',
        'cookie',
        'date_of_birth',
        'dob',
        'email',
        'file_contents',
        'password',
        'phone',
        'phone_number',
        'secret',
        'secure_url',
        'token',
    ];

    public const REDACTED = '[REDACTED]';

    public function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = $this->isSensitiveKey((string) $key)
                ? self::REDACTED
                : $this->redact($item);
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($normalizedKey === $sensitiveKey || str_contains($normalizedKey, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
