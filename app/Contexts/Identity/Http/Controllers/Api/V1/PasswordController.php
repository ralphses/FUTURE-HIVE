<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\ChangePasswordAction;
use App\Contexts\Identity\Application\Actions\PasswordRecoveryAction;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Http\Requests\ChangePasswordRequest;
use App\Contexts\Identity\Http\Requests\ForgotPasswordRequest;
use App\Contexts\Identity\Http\Requests\ResetPasswordRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PasswordController
{
    /**
     * @response 202 array{data: array{message: string}}
     */
    public function forgot(ForgotPasswordRequest $request, PasswordRecoveryAction $recovery): JsonResponse
    {
        $recovery->request(
            $request->string('login')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::data([
            'message' => 'If the account can be recovered, a password reset code will be delivered.',
        ], 202);
    }

    /**
     * @response array{data: array{password_reset: bool}}
     */
    public function reset(ResetPasswordRequest $request, PasswordRecoveryAction $recovery): JsonResponse
    {
        $recovery->reset(
            $request->string('login')->toString(),
            $request->string('code')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::data(['password_reset' => true]);
    }

    /**
     * @response array{data: array{password_changed: bool}}
     */
    public function change(ChangePasswordRequest $request, ChangePasswordAction $change): JsonResponse
    {
        $identity = $request->user();
        $session = $request->attributes->get('auth_session');

        if (! $identity instanceof UserIdentity || ! $session instanceof AuthSession) {
            abort(401);
        }

        $change->change(
            $identity,
            $session,
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::data(['password_changed' => true]);
    }
}
