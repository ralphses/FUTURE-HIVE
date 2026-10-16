<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Infrastructure\Privacy;

use App\Contexts\Platform\Application\Actions\AiConsentAction;
use App\Contexts\Platform\Application\Contracts\AiPrivacyBoundary;
use Illuminate\Contracts\Auth\Authenticatable;

final class DeterministicAiPrivacyBoundary implements AiPrivacyBoundary
{
    public function __construct(private readonly AiConsentAction $consents) {}

    public function prepare(Authenticatable $actor, string $featureScope, array $payload, bool $sensitive): array
    {
        return $this->consents->prepare($actor, $featureScope, $payload, $sensitive);
    }
}
