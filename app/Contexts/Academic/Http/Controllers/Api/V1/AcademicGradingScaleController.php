<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicGradingScaleAction;
use App\Contexts\Academic\Http\Requests\CreateAcademicGradingScaleRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicGradingScaleController
{
    public function index(Request $request, string $school, string $session, string $term, string $offering, string $policy, AcademicGradingScaleAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term, $offering, $policy));
    }

    public function show(Request $request, string $school, string $session, string $term, string $offering, string $policy, string $scale, AcademicGradingScaleAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $offering, $policy, $scale));
    }

    public function store(CreateAcademicGradingScaleRequest $request, string $school, string $session, string $term, string $offering, string $policy, AcademicGradingScaleAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $offering, $policy, $request->validated()), 201);
    }

    public function activate(Request $request, string $school, string $session, string $term, string $offering, string $policy, string $scale, AcademicGradingScaleAction $action): JsonResponse
    {
        return ApiResponse::data($action->activate($this->identity($request), $school, $session, $term, $offering, $policy, $scale));
    }

    public function retire(Request $request, string $school, string $session, string $term, string $offering, string $policy, string $scale, AcademicGradingScaleAction $action): JsonResponse
    {
        return ApiResponse::data($action->retire($this->identity($request), $school, $session, $term, $offering, $policy, $scale));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
