<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SubmitPublicDemoEnquiryAction;
use App\Contexts\Platform\Domain\Exceptions\IdempotencyKeyReused;
use App\Contexts\Platform\Http\Requests\StorePublicDemoEnquiryRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

final class PublicDemoEnquiryController
{
    #[Endpoint(operationId: 'v1.public.demo-enquiries.store', title: 'Submit a demo or contact enquiry', description: 'Accepts a bounded public enquiry for later review. It does not create a school, identity, membership, credential, session or tenant context, and does not send external notifications.')]
    #[HeaderParameter(name: 'Idempotency-Key', description: 'A client-generated retry key. Reuse it only with the same enquiry payload.', type: 'string', required: true)]
    #[Header(name: 'X-Request-ID', description: 'Request correlation identifier.', type: 'string', format: 'uuid', required: true, status: 202)]
    #[Response(status: 202, description: 'A generic accepted acknowledgement.', type: 'array')]
    #[Response(status: 409, description: 'The idempotency key was reused for a different payload.')]
    public function store(StorePublicDemoEnquiryRequest $request, SubmitPublicDemoEnquiryAction $action): JsonResponse
    {
        try {
            $result = $action->execute($request->validated(), $request->idempotencyKey(), Context::get('request_id'), $request->ip(), $request->userAgent());
        } catch (IdempotencyKeyReused $exception) {
            throw $exception;
        }

        return ApiResponse::data($result, 202);
    }
}
