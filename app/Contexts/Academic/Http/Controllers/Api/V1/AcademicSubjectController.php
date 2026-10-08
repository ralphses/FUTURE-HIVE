<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicSubjectAction;
use App\Contexts\Academic\Http\Requests\StoreAcademicSubjectRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicSubjectRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicSubjectController
{
    public function index(Request $request, string $school, AcademicSubjectAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    public function show(Request $request, string $school, string $subject, AcademicSubjectAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $subject));
    }

    public function store(StoreAcademicSubjectRequest $request, string $school, AcademicSubjectAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    public function update(UpdateAcademicSubjectRequest $request, string $school, string $subject, AcademicSubjectAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $subject, $request->validated()));
    }

    public function activate(Request $request, string $school, string $subject, AcademicSubjectAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $subject, 'active'));
    }

    public function deactivate(Request $request, string $school, string $subject, AcademicSubjectAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $subject, 'inactive'));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
