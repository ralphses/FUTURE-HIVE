<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\OperationalReportScheduleAction;
use App\Contexts\Platform\Http\Requests\StoreOperationalReportScheduleRequest;
use App\Contexts\Platform\Http\Requests\UpdateOperationalReportScheduleRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationalReportScheduleController
{
    #[Endpoint(title: 'List operational report schedules', description: 'Lists safe schedule configuration in the trusted school.')]
    public function index(Request $request, string $school, OperationalReportScheduleAction $action): JsonResponse
    {
        return ApiResponse::data(['schedules' => $action->index($this->identity($request), $school)]);
    }

    #[Endpoint(title: 'Create an operational report schedule', description: 'Creates an idempotent draft schedule. The server controls school ownership and report scope.')]
    public function store(StoreOperationalReportScheduleRequest $request, string $school, OperationalReportScheduleAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View an operational report schedule', description: 'Shows one safe schedule projection without internal identifiers or private metadata.')]
    public function show(Request $request, string $school, string $schedule, OperationalReportScheduleAction $action): JsonResponse
    {
        $items = $action->index($this->identity($request), $school);
        $item = collect($items)->firstWhere('id', $schedule);
        abort_unless(is_array($item), 404);

        return ApiResponse::data(['schedule' => $item]);
    }

    #[Endpoint(title: 'Update an operational report schedule', description: 'Updates only a draft schedule; active, paused and retired schedules are protected by lifecycle rules.')]
    public function update(UpdateOperationalReportScheduleRequest $request, string $school, string $schedule, OperationalReportScheduleAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $schedule, $request->validated()));
    }

    #[Endpoint(title: 'Change operational report schedule lifecycle', description: 'Activates, pauses or retires a schedule with server-controlled idempotent transitions.')]
    public function lifecycle(Request $request, string $school, string $schedule, string $transition, OperationalReportScheduleAction $action): JsonResponse
    {
        abort_unless(in_array($transition, ['activate', 'pause', 'retire'], true), 404);

        return ApiResponse::data($action->lifecycle($this->identity($request), $school, $schedule, $transition));
    }

    private function identity(Request $request): object
    {
        $identity = $request->user();
        abort_unless(is_a($identity, 'App\\Contexts\\Identity\\Domain\\Models\\UserIdentity'), 401);

        return $identity;
    }
}
