<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAttendanceAction;
use App\Contexts\Academic\Http\Requests\AttendanceReportRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class AcademicAttendanceReportController
{
    #[Endpoint(title: 'View attendance report', description: 'Shows a read-only attendance summary for submitted and locked registers in a selected class arm and date range. Draft and missing registers are excluded from attendance-rate calculations.')]
    #[Response(status: 200, description: 'The safe aggregate attendance report.', type: 'array')]
    public function show(AttendanceReportRequest $request, string $school, string $session, string $term, string $classArm, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->report($this->identity($request), $school, $session, $term, $classArm, $request->validated()));
    }

    private function identity(AttendanceReportRequest $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
