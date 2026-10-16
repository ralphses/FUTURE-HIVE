<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface AiPrivacyBoundary
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{allowed: bool, payload: array<string, mixed>, issue_code: string|null, provider_status: string}
     */
    public function prepare(Authenticatable $actor, string $featureScope, array $payload, bool $sensitive): array;
}
