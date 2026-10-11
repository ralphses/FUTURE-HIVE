<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAttendanceAction;
use App\Contexts\Academic\Http\Requests\CorrectAcademicAttendanceRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAttendanceCorrectionController
{
    #[Endpoint(title: 'View attendance correction history', description: 'Lists the append-only correction history for a submitted attendance register. Locked registers remain immutable and correction records expose only safe public references and state changes.')]
    #[Response(status: 200, description: 'The sanitized attendance correction history.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, string $classArm, string $date, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->corrections($this->identity($request), $school, $session, $term, $classArm, $date));
    }

    #[Endpoint(title: 'Correct submitted attendance', description: 'Corrects one entry in a submitted attendance register with a required bounded reason. The prior state remains in append-only history; locked registers cannot be corrected or reopened.')]
    #[Response(status: 200, description: 'The corrected attendance entry and safe correction record.', type: 'array')]
    public function store(CorrectAcademicAttendanceRequest $request, string $school, string $session, string $term, string $classArm, string $date, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->correct($this->identity($request), $school, $session, $term, $classArm, $date, $request->validated()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
