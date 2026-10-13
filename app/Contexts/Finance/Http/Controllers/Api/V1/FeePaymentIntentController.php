<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FeePaymentIntentAction;
use App\Contexts\Finance\Http\Requests\StoreFeePaymentIntentRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeePaymentIntentController
{
    #[Endpoint(title: 'List payment intents', description: 'Lists safe provider-agnostic payment intents for an issued school invoice.')]
    public function index(Request $request, string $school, string $invoice, FeePaymentIntentAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school, $invoice));
    }

    #[Endpoint(title: 'Create payment intent', description: 'Creates an idempotent provider-agnostic payment intent without confirming payment or posting financial activity.')]
    #[HeaderParameter(name: 'Idempotency-Key', description: 'A client-generated retry key. Reuse it only with the same request payload.', type: 'string', required: true)]
    public function store(StoreFeePaymentIntentRequest $request, string $school, string $invoice, FeePaymentIntentAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $invoice, $request->validated(), $request->idempotencyKey()), 201);
    }

    #[Endpoint(title: 'View payment intent', description: 'Returns safe payment-intent state without exposing provider data or credentials.')]
    public function show(Request $request, string $school, string $invoice, string $intent, FeePaymentIntentAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $invoice, $intent));
    }

    #[Endpoint(title: 'Cancel payment intent', description: 'Cancels a provider-agnostic payment intent before provider confirmation.')]
    public function cancel(Request $request, string $school, string $invoice, string $intent, FeePaymentIntentAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($this->identity($request), $school, $invoice, $intent));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
