<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\ProcessPaymentWebhookAction;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentWebhookController
{
    #[Endpoint(title: 'Verify payment webhook', description: 'Verifies a signed provider event and safely advances its bound payment intent without posting allocations or ledger activity.')]
    public function __invoke(Request $request, string $provider, ProcessPaymentWebhookAction $action): JsonResponse
    {
        return ApiResponse::data($action->execute($provider, $request->getContent(), $request->header('X-Payment-Signature')));
    }
}
