<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Http\Controllers\Api\V1;

use App\Contexts\Identity\Application\Actions\ResolveSchoolContextAction;
use App\Contexts\Identity\Application\Actions\SwitchSchoolContextAction;
use App\Contexts\Identity\Domain\Models\AuthSession;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Http\Requests\SwitchSchoolContextRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolContextController
{
    /** @response array{data: array{school_id: string, school_name: string, membership_id: string, is_owner: bool}} */
    public function select(
        SwitchSchoolContextRequest $request,
        SwitchSchoolContextAction $switchContext,
    ): JsonResponse {
        $identity = $request->user();
        $session = $request->attributes->get('auth_session');

        abort_unless($identity instanceof UserIdentity && $session instanceof AuthSession, 401);

        return ApiResponse::data($switchContext->handle(
            $identity,
            $session,
            $request->string('school_id')->toString(),
        )->toArray());
    }

    /** @response array{data: array{school_id: string, school_name: string, membership_id: string, is_owner: bool}|null} */
    public function show(Request $request, ResolveSchoolContextAction $resolveContext): JsonResponse
    {
        $identity = $request->user();
        $session = $request->attributes->get('auth_session');

        abort_unless($identity instanceof UserIdentity && $session instanceof AuthSession, 401);

        return ApiResponse::data($resolveContext->handle($identity, $session)?->toArray() ?? []);
    }
}
