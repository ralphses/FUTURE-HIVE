<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicClassArmAction;
use App\Contexts\Academic\Http\Requests\StoreAcademicClassArmRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicClassArmRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicClassArmController
{
    public function index(Request $request, string $school, string $level, string $section, AcademicClassArmAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $level, $section));
    }

    public function show(Request $request, string $school, string $level, string $section, string $classArm, AcademicClassArmAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $level, $section, $classArm));
    }

    public function store(StoreAcademicClassArmRequest $request, string $school, string $level, string $section, AcademicClassArmAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $level, $section, $request->validated()), 201);
    }

    public function update(UpdateAcademicClassArmRequest $request, string $school, string $level, string $section, string $classArm, AcademicClassArmAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $level, $section, $classArm, $request->validated()));
    }

    public function activate(Request $request, string $school, string $level, string $section, string $classArm, AcademicClassArmAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $level, $section, $classArm, 'active'));
    }

    public function deactivate(Request $request, string $school, string $level, string $section, string $classArm, AcademicClassArmAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $level, $section, $classArm, 'inactive'));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
