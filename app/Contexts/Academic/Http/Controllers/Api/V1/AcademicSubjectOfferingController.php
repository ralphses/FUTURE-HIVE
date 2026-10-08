<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicSubjectOfferingAction;
use App\Contexts\Academic\Http\Requests\StoreAcademicSubjectOfferingRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicSubjectOfferingRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicSubjectOfferingController
{
    public function index(Request $request, string $school, string $session, string $term, AcademicSubjectOfferingAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term));
    }

    public function show(Request $request, string $school, string $session, string $term, string $offering, AcademicSubjectOfferingAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $offering));
    }

    public function store(StoreAcademicSubjectOfferingRequest $request, string $school, string $session, string $term, AcademicSubjectOfferingAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $request->validated()), 201);
    }

    public function update(UpdateAcademicSubjectOfferingRequest $request, string $school, string $session, string $term, string $offering, AcademicSubjectOfferingAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $offering, $request->validated()));
    }

    public function activate(Request $request, string $school, string $session, string $term, string $offering, AcademicSubjectOfferingAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $session, $term, $offering, 'active'));
    }

    public function deactivate(Request $request, string $school, string $session, string $term, string $offering, AcademicSubjectOfferingAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $session, $term, $offering, 'inactive'));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
