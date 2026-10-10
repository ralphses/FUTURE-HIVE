<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentEnrollmentAction;
use App\Contexts\Registry\Http\Requests\EndStudentEnrollmentRequest;
use App\Contexts\Registry\Http\Requests\StoreStudentEnrollmentRequest;
use App\Contexts\Registry\Http\Requests\TransferStudentEnrollmentRequest;
use App\Contexts\Registry\Http\Requests\WithdrawStudentEnrollmentRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentEnrollmentController
{
    #[Endpoint(title: 'List student enrollments', description: 'Lists the selected student’s term enrollments in the trusted school. Internal identifiers and unrelated school records are never returned.')]
    public function index(Request $request, string $school, string $student, StudentEnrollmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $student));
    }

    #[Endpoint(title: 'Enroll a student', description: 'Places an active student into an active class arm for an academic term. Parent records and ownership are resolved by the server; the request cannot create identity or guardian access.')]
    public function store(StoreStudentEnrollmentRequest $request, string $school, string $student, StudentEnrollmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $student, $request->validated()), 201);
    }

    #[Endpoint(title: 'View a student enrollment', description: 'Returns one school-scoped enrollment using its public identifier.')]
    public function show(Request $request, string $school, string $student, string $enrollment, StudentEnrollmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $student, $enrollment));
    }

    #[Endpoint(title: 'End a student enrollment', description: 'Administratively ends an active enrollment and retains its history. Transfers and replacement placement are handled by a later workflow.')]
    public function end(EndStudentEnrollmentRequest $request, string $school, string $student, string $enrollment, StudentEnrollmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->end($this->identity($request), $school, $student, $enrollment, $request->string('reason')->toString()));
    }

    #[Endpoint(title: 'Transfer a student enrollment', description: 'Moves an active student enrollment to another available class arm in the same school and academic term. The original placement is retained as history and the change is atomic.')]
    public function transfer(TransferStudentEnrollmentRequest $request, string $school, string $student, string $enrollment, StudentEnrollmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->transfer($this->identity($request), $school, $student, $enrollment, $request->validated()));
    }

    #[Endpoint(title: 'Withdraw a student enrollment', description: 'Ends an active placement for an administrative withdrawal reason while retaining the enrollment history. It does not change the student admission record.')]
    public function withdraw(WithdrawStudentEnrollmentRequest $request, string $school, string $student, string $enrollment, StudentEnrollmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->withdraw($this->identity($request), $school, $student, $enrollment, $request->string('reason')->toString()));
    }

    #[Endpoint(title: 'View enrollment history', description: 'Lists sanitized transfer and withdrawal changes for a student in the selected school. Internal identifiers and sensitive metadata are excluded.')]
    public function history(Request $request, string $school, string $student, StudentEnrollmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->history($this->identity($request), $school, $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
