<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PlatformBillingAction;
use App\Contexts\Platform\Http\Requests\PlatformBillingInvoiceRequest;
use App\Contexts\Platform\Http\Requests\PlatformBillingPaymentRequest;
use App\Contexts\Platform\Http\Requests\PlatformBillingVoidRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformBillingController
{
    #[Endpoint(operationId: 'v1.platform.billing.invoices.index', title: 'List SaaS billing invoices', description: 'Lists safe school-scoped SaaS invoices with immutable price snapshots. School-fee finance records are not included.')]
    #[Response(status: 200, description: 'Paginated safe SaaS invoices.', type: 'array')]
    public function index(Request $request, string $school, PlatformBillingAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->invoices($this->identity($request), $school, $request->only(['status', 'per_page'])));
    }

    #[Endpoint(operationId: 'v1.platform.billing.invoices.show', title: 'View SaaS billing invoice', description: 'Returns a safe SaaS invoice projection without provider secrets, contacts or internal identifiers.')]
    #[Response(status: 200, description: 'A safe SaaS invoice.', type: 'array')]
    public function show(Request $request, string $school, string $invoice, PlatformBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->invoice($this->identity($request), $school, $invoice));
    }

    #[Endpoint(operationId: 'v1.platform.billing.invoices.store', title: 'Create SaaS billing invoice', description: 'Creates an idempotent draft SaaS invoice from the active school subscription and snapshots the selected price and plan version.')]
    #[Response(status: 201, description: 'A created SaaS invoice.', type: 'array')]
    public function store(PlatformBillingInvoiceRequest $request, string $school, PlatformBillingAction $action): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $idempotencyKey = is_string($idempotencyKey) ? $idempotencyKey : (string) $request->validated('idempotency_key');

        return ApiResponse::data($action->createInvoice($this->identity($request), $school, [...$request->validated(), 'idempotency_key' => $idempotencyKey]), 201);
    }

    #[Endpoint(operationId: 'v1.platform.billing.invoices.issue', title: 'Issue SaaS billing invoice', description: 'Issues a draft SaaS invoice through a server-controlled lifecycle without changing entitlements or school-fee finance.')]
    #[Response(status: 200, description: 'The issued SaaS invoice.', type: 'array')]
    public function issue(Request $request, string $school, string $invoice, PlatformBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionInvoice($this->identity($request), $school, $invoice, 'issue'));
    }

    #[Endpoint(operationId: 'v1.platform.billing.invoices.void', title: 'Void SaaS billing invoice', description: 'Voids an eligible SaaS invoice with a bounded reason while retaining its historical record.')]
    #[Response(status: 200, description: 'The voided SaaS invoice.', type: 'array')]
    public function void(PlatformBillingVoidRequest $request, string $school, string $invoice, PlatformBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionInvoice($this->identity($request), $school, $invoice, 'void', $request->validated('reason')));
    }

    #[Endpoint(operationId: 'v1.platform.billing.payments.store', title: 'Initiate SaaS payment', description: 'Creates a provider-neutral pending payment record with a required idempotency key and no provider call.')]
    #[Response(status: 201, description: 'A pending SaaS payment.', type: 'array')]
    public function payment(PlatformBillingPaymentRequest $request, string $school, string $invoice, PlatformBillingAction $action): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $idempotencyKey = is_string($idempotencyKey) ? $idempotencyKey : (string) $request->validated('idempotency_key');

        return ApiResponse::data($action->createPayment($this->identity($request), $school, $invoice, [...$request->validated(), 'idempotency_key' => $idempotencyKey]), 201);
    }

    #[Endpoint(operationId: 'v1.platform.billing.payments.show', title: 'View SaaS payment status', description: 'Returns safe payment state without provider payloads, credentials or raw references.')]
    #[Response(status: 200, description: 'A safe SaaS payment status.', type: 'array')]
    public function paymentShow(Request $request, string $school, string $invoice, string $payment, PlatformBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->payment($this->identity($request), $school, $invoice, $payment));
    }

    #[Endpoint(operationId: 'v1.platform.billing.payments.refund', title: 'Record SaaS payment refund', description: 'Records an approved provider-neutral refund state without changing entitlements, school fees or ledgers.')]
    #[Response(status: 200, description: 'The refunded SaaS payment.', type: 'array')]
    public function refund(PlatformBillingVoidRequest $request, string $school, string $payment, PlatformBillingAction $action): JsonResponse
    {
        return ApiResponse::data($action->refund($this->identity($request), $school, $payment, $request->validated('reason')));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
