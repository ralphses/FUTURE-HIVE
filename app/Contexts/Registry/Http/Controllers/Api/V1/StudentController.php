<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentAdmissionAction;
use App\Contexts\Registry\Application\Actions\StudentGuardianAuditTrailAction;
use App\Contexts\Registry\Http\Requests\StoreStudentRequest;
use App\Contexts\Registry\Http\Requests\UpdateStudentRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentController
{
    public function index(Request $request, string $school, StudentAdmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    public function show(Request $request, string $school, string $student, StudentAdmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $student));
    }

    public function store(StoreStudentRequest $request, string $school, StudentAdmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    public function update(UpdateStudentRequest $request, string $school, string $student, StudentAdmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $student, $request->validated()));
    }

    public function activate(Request $request, string $school, string $student, StudentAdmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $student, 'active'));
    }

    public function withdraw(Request $request, string $school, string $student, StudentAdmissionAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $student, 'withdrawn'));
    }

    #[Endpoint(operationId: 'v1.schools.students.audit-history', title: 'View student audit history', description: 'Lists safe, read-only history for sensitive student and placement changes in the selected school. Contacts, credentials, file contents and internal identifiers are excluded.')]
    #[Response(status: 200, description: 'A paginated safe audit history response.', type: 'array')]
    public function auditHistory(Request $request, string $school, string $student, StudentGuardianAuditTrailAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->forStudent($this->identity($request), $school, $student));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
