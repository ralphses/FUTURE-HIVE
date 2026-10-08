<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Models\SecurityEvent;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Observability\SensitiveDataRedactor;
use Illuminate\Support\Facades\Context;

final class RecordSecurityEventAction
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /** @param array<string, mixed> $context */
    public function execute(
        string $eventType,
        string $outcome,
        ?UserIdentity $identity = null,
        ?string $login = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $context = [],
    ): SecurityEvent {
        $key = (string) config('app.key');

        return SecurityEvent::query()->create([
            'user_id' => $identity?->id,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'request_id' => Context::get('request_id'),
            'login_hash' => $login === null ? null : hash_hmac('sha256', mb_strtolower(trim($login)), $key),
            'ip_hash' => $ipAddress === null ? null : hash_hmac('sha256', $ipAddress, $key),
            'user_agent_hash' => $userAgent === null ? null : hash_hmac('sha256', $userAgent, $key),
            'context' => $this->redactor->redact($context),
        ]);
    }
}
