<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\ContactVerificationAction;
use App\Contexts\Identity\Http\Requests\ConfirmContactVerificationRequest;
use App\Contexts\Identity\Http\Requests\RequestContactVerificationRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ContactVerificationController
{
    /**
     * @response 202 array{data: array{message: string}}
     */
    public function request(RequestContactVerificationRequest $request, ContactVerificationAction $verification): JsonResponse
    {
        $verification->request(
            $request->string('contact')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::data([
            'message' => 'If the contact can be verified, a verification code will be delivered.',
        ], 202);
    }

    /**
     * @response array{data: array{verified: bool}}
     */
    public function confirm(ConfirmContactVerificationRequest $request, ContactVerificationAction $verification): JsonResponse
    {
        $verification->confirm(
            $request->string('contact')->toString(),
            $request->string('code')->toString(),
        );

        return ApiResponse::data(['verified' => true]);
    }
}
