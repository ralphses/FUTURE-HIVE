<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicStructureAction;
use App\Contexts\Academic\Http\Requests\StoreAcademicLevelRequest;
use App\Contexts\Academic\Http\Requests\StoreAcademicSectionRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicLevelRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicSectionRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicStructureController
{
    public function levels(Request $request, string $school, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->levels($this->identity($request), $school));
    }

    public function showLevel(Request $request, string $school, string $level, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->level($this->identity($request), $school, $level));
    }

    public function storeLevel(StoreAcademicLevelRequest $request, string $school, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->createLevel($this->identity($request), $school, $request->validated()), 201);
    }

    public function updateLevel(UpdateAcademicLevelRequest $request, string $school, string $level, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateLevel($this->identity($request), $school, $level, $request->validated()));
    }

    public function activateLevel(Request $request, string $school, string $level, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionLevel($this->identity($request), $school, $level, 'active'));
    }

    public function deactivateLevel(Request $request, string $school, string $level, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionLevel($this->identity($request), $school, $level, 'inactive'));
    }

    public function sections(Request $request, string $school, string $level, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->sections($this->identity($request), $school, $level));
    }

    public function showSection(Request $request, string $school, string $level, string $section, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->section($this->identity($request), $school, $level, $section));
    }

    public function storeSection(StoreAcademicSectionRequest $request, string $school, string $level, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->createSection($this->identity($request), $school, $level, $request->validated()), 201);
    }

    public function updateSection(UpdateAcademicSectionRequest $request, string $school, string $level, string $section, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateSection($this->identity($request), $school, $level, $section, $request->validated()));
    }

    public function activateSection(Request $request, string $school, string $level, string $section, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionSection($this->identity($request), $school, $level, $section, 'active'));
    }

    public function deactivateSection(Request $request, string $school, string $level, string $section, AcademicStructureAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionSection($this->identity($request), $school, $level, $section, 'inactive'));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
