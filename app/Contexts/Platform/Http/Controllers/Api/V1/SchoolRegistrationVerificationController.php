<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SchoolRegistrationVerificationAction;
use App\Contexts\Platform\Domain\Exceptions\RegistrationVerificationFailed;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolRegistrationVerificationController
{
    /** @response 202 array{data: array{message: string}} */
    #[Endpoint(
        title: 'Request provisional registration verification',
        description: 'Requests verification for the registration contact stored by SchoolOS. The response is generic and does not reveal registration or contact state.',
    )]
    #[Header(name: 'X-Request-ID', description: 'Request correlation identifier.', type: 'string', format: 'uuid', required: true, status: 202)]
    public function request(Request $request, string $registration, SchoolRegistrationVerificationAction $verification): JsonResponse
    {
        $verification->request($registration, $request->ip(), $request->userAgent());

        return ApiResponse::data([
            'message' => 'If the registration can be verified, a verification code will be delivered.',
        ], 202);
    }

    /** @response array{data: array{verified: bool, status: string}} */
    #[Endpoint(
        title: 'Confirm provisional registration verification',
        description: 'Confirms a six-digit code for a provisional registration without creating an identity, school, membership, credential or session.',
    )]
    #[Response(status: 400, description: 'The verification code is invalid, expired, consumed, revoked or exhausted.')]
    #[BodyParameter(name: 'code', description: 'The six-digit verification code delivered to the stored registration contact.', type: 'string', required: true, example: '123456')]
    #[Header(name: 'X-Request-ID', description: 'Request correlation identifier.', type: 'string', format: 'uuid', required: true, status: 200)]
    public function confirm(Request $request, string $registration, SchoolRegistrationVerificationAction $verification): JsonResponse
    {
        $code = $request->string('code')->toString();

        if (! preg_match('/^\d{6}$/', $code)) {
            throw new RegistrationVerificationFailed;
        }

        $verification->confirm($registration, $code);

        return ApiResponse::data(['verified' => true, 'status' => 'verified']);
    }
}
