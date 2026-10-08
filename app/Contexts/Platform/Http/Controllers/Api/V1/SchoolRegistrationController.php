<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\SubmitSchoolRegistrationAction;
use App\Contexts\Platform\Application\DTOs\SubmitSchoolRegistrationData;
use App\Contexts\Platform\Domain\Enums\SchoolRegistrationStatus;
use App\Contexts\Platform\Domain\Exceptions\IdempotencyKeyReused;
use App\Contexts\Platform\Http\Requests\CreateSchoolRegistrationRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SchoolRegistrationController
{
    /** @response 202 array{data: array{registration_id: string, status: string}} */
    #[Endpoint(
        title: 'Submit provisional school registration',
        description: 'Creates an intake record only. It does not create a school, identity, membership, credential or session.',
    )]
    #[HeaderParameter(
        name: 'Idempotency-Key',
        description: 'A client-generated retry key. Reuse it only with the same request payload.',
        type: 'string',
        required: true,
    )]
    #[Response(status: 409, description: 'The idempotency key was reused for a different request fingerprint.')]
    #[Header(
        name: 'X-Request-ID',
        description: 'Request correlation identifier.',
        type: 'string',
        format: 'uuid',
        required: true,
        status: 202,
    )]
    public function store(
        CreateSchoolRegistrationRequest $request,
        SubmitSchoolRegistrationAction $action,
    ): JsonResponse {
        if (! preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $request->idempotencyKey())) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['The Idempotency-Key header must be 16 to 128 safe characters.'],
            ]);
        }

        try {
            $registration = $action->execute(new SubmitSchoolRegistrationData(
                schoolName: $request->string('school_name')->toString(),
                schoolType: $request->string('school_type')->toString(),
                state: $request->string('state')->toString(),
                contact: $request->string('contact')->toString(),
                consentVersion: $request->string('consent_version')->toString(),
                idempotencyKey: $request->idempotencyKey(),
                requestId: Context::get('request_id'),
                ipAddress: (string) $request->ip(),
                userAgent: $request->userAgent(),
            ));
        } catch (IdempotencyKeyReused $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contact' => [$exception->getMessage()]]);
        }

        return ApiResponse::data([
            'registration_id' => $registration->public_id,
            'status' => SchoolRegistrationStatus::from((string) $registration->getRawOriginal('status'))->value,
        ], 202);
    }
}
