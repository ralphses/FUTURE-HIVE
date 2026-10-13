<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinanceReminderAction;
use App\Contexts\Finance\Http\Requests\StoreFinanceReminderRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceReminderController
{
    #[Endpoint(title: 'List fee reminder candidates', description: 'Lists safe eligible outstanding invoice candidates for authorized finance staff. No recipient contact data is returned.')]
    public function candidates(Request $request, string $school, FinanceReminderAction $action): JsonResponse
    {
        return ApiResponse::data($action->candidates($this->identity($request), $school));
    }

    #[Endpoint(title: 'Create fee reminder', description: 'Creates an idempotent school-scoped fee reminder for an active verified guardian relationship.')]
    public function store(StoreFinanceReminderRequest $request, string $school, FinanceReminderAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View fee reminder', description: 'Returns safe fee reminder state and financial metadata without recipient contact data.')]
    public function show(Request $request, string $school, string $reminder, FinanceReminderAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $reminder));
    }

    #[Endpoint(title: 'Send fee reminder', description: 'Sends a reminder through the configured application delivery contract. Local tests use a deterministic fake and do not make network calls.')]
    public function send(Request $request, string $school, string $reminder, FinanceReminderAction $action): JsonResponse
    {
        return ApiResponse::data($action->send($this->identity($request), $school, $reminder));
    }

    #[Endpoint(title: 'Cancel fee reminder', description: 'Cancels a queued or otherwise unsent fee reminder without changing any financial record.')]
    public function cancel(Request $request, string $school, string $reminder, FinanceReminderAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($this->identity($request), $school, $reminder));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
