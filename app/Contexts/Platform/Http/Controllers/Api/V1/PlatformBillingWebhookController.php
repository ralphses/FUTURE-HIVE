<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PlatformBillingAction;
use App\Contexts\Platform\Application\Contracts\PlatformBillingWebhookVerifier;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformBillingWebhookController
{
    public function __construct(private readonly PlatformBillingWebhookVerifier $verifier) {}

    #[Endpoint(operationId: 'v1.platform.billing.webhooks.verify', title: 'Verify SaaS billing webhook', description: 'Verifies a signed provider event and updates only the matching provider-neutral SaaS payment state. Raw payloads, secrets and provider data are never persisted or returned.')]
    #[Response(status: 200, description: 'A safe webhook processing result.', type: 'array')]
    public function __invoke(Request $request, string $provider, PlatformBillingAction $action): JsonResponse
    {
        $rawPayload = (string) $request->getContent();
        $event = $this->verifier->verify($provider, $rawPayload, $request->header('X-Provider-Signature'));
        $result = $action->processWebhook($provider, $event, hash_hmac('sha256', $rawPayload, (string) config('app.key')), hash_hmac('sha256', $provider.'|'.$event['event_id'], (string) config('app.key')));

        return response()->json(['data' => $result]);
    }
}
