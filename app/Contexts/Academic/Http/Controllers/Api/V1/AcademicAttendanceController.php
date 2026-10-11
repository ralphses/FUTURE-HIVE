<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicAttendanceAction;
use App\Contexts\Academic\Http\Requests\ReplaceAcademicAttendanceRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicAttendanceController
{
    #[Endpoint(title: 'View daily class attendance', description: 'Shows the selected class arm attendance roster for one date. Students without a recorded mark remain unrecorded and are never treated as absent.')]
    #[Response(status: 200, description: 'The safe attendance roster and draft register state.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $classArm, string $date, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $classArm, $date));
    }

    #[Endpoint(title: 'Record daily class attendance', description: 'Records attendance marks for active students currently enrolled in the selected class arm. Only present, absent, late, excused and unrecorded are accepted; the register remains editable and in draft state until a later workflow submits it.')]
    #[Response(status: 200, description: 'The updated draft attendance roster.', type: 'array')]
    public function replace(ReplaceAcademicAttendanceRequest $request, string $school, string $session, string $term, string $classArm, string $date, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->replace($this->identity($request), $school, $session, $term, $classArm, $date, $request->validated()));
    }

    #[Endpoint(title: 'Submit daily class attendance', description: 'Submits a complete draft attendance register for review. Every currently enrolled student must have an explicit status, including unrecorded; the server never infers absence.')]
    #[Response(status: 200, description: 'The submitted attendance register.', type: 'array')]
    public function submit(Request $request, string $school, string $session, string $term, string $classArm, string $date, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->submit($this->identity($request), $school, $session, $term, $classArm, $date));
    }

    #[Endpoint(title: 'Lock daily class attendance', description: 'Locks a submitted attendance register. Only authorized school leadership can perform this irreversible action in the current slice.')]
    #[Response(status: 200, description: 'The locked attendance register.', type: 'array')]
    public function lock(Request $request, string $school, string $session, string $term, string $classArm, string $date, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->lock($this->identity($request), $school, $session, $term, $classArm, $date));
    }

    #[Endpoint(title: 'View attendance submission status', description: 'Shows whether the selected attendance register is still a draft, submitted or locked. It does not open or modify the register.')]
    #[Response(status: 200, description: 'The attendance submission state.', type: 'array')]
    public function submission(Request $request, string $school, string $session, string $term, string $classArm, string $date, AcademicAttendanceAction $action): JsonResponse
    {
        return ApiResponse::data($action->submission($this->identity($request), $school, $session, $term, $classArm, $date));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
