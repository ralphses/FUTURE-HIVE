<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicPeriodAction;
use App\Contexts\Academic\Http\Requests\AcademicPeriodTransitionRequest;
use App\Contexts\Academic\Http\Requests\StoreAcademicSessionRequest;
use App\Contexts\Academic\Http\Requests\StoreAcademicTermRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicSessionRequest;
use App\Contexts\Academic\Http\Requests\UpdateAcademicTermRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicPeriodController
{
    public function sessions(Request $request, string $school, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->sessions($this->identity($request), $school));
    }

    public function showSession(Request $request, string $school, string $session, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->session($this->identity($request), $school, $session));
    }

    public function storeSession(StoreAcademicSessionRequest $request, string $school, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->createSession($this->identity($request), $school, $request->validated()), 201);
    }

    public function updateSession(UpdateAcademicSessionRequest $request, string $school, string $session, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateSession($this->identity($request), $school, $session, $request->validated()));
    }

    public function activateSession(AcademicPeriodTransitionRequest $request, string $school, string $session, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionSession($this->identity($request), $school, $session, 'active', $request->string('reason')->toString()));
    }

    public function closeSession(AcademicPeriodTransitionRequest $request, string $school, string $session, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionSession($this->identity($request), $school, $session, 'closed', $request->string('reason')->toString()));
    }

    public function terms(Request $request, string $school, string $session, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->terms($this->identity($request), $school, $session));
    }

    public function storeTerm(StoreAcademicTermRequest $request, string $school, string $session, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->createTerm($this->identity($request), $school, $session, $request->validated()), 201);
    }

    public function updateTerm(UpdateAcademicTermRequest $request, string $school, string $session, string $term, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateTerm($this->identity($request), $school, $session, $term, $request->validated()));
    }

    public function activateTerm(AcademicPeriodTransitionRequest $request, string $school, string $session, string $term, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionTerm($this->identity($request), $school, $session, $term, 'active', $request->string('reason')->toString()));
    }

    public function closeTerm(AcademicPeriodTransitionRequest $request, string $school, string $session, string $term, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->transitionTerm($this->identity($request), $school, $session, $term, 'closed', $request->string('reason')->toString()));
    }

    public function context(Request $request, string $school, AcademicPeriodAction $action): JsonResponse
    {
        return ApiResponse::data($action->context($this->identity($request), $school));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
