<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\CounsellingReferralAction;
use App\Contexts\Registry\Http\Requests\StoreCounsellingReferralRequest;
use App\Contexts\Registry\Http\Requests\TransitionCounsellingCaseRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CounsellingReferralController
{
    public function indexReferral(Request $request, string $school, CounsellingReferralAction $action): JsonResponse
    {
        return $this->index($request, $school, 'referral', $action);
    }

    public function storeReferral(StoreCounsellingReferralRequest $request, string $school, CounsellingReferralAction $action): JsonResponse
    {
        return $this->store($request, $school, 'referral', $action);
    }

    public function showReferral(Request $request, string $school, string $referral, CounsellingReferralAction $action): JsonResponse
    {
        return $this->show($request, $school, 'referral', $referral, $action);
    }

    public function approveReferral(TransitionCounsellingCaseRequest $request, string $school, string $referral, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'referral', $referral, 'approved', $action);
    }

    public function rejectReferral(TransitionCounsellingCaseRequest $request, string $school, string $referral, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'referral', $referral, 'rejected', $action);
    }

    public function acceptReferral(TransitionCounsellingCaseRequest $request, string $school, string $referral, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'referral', $referral, 'accepted', $action);
    }

    public function closeReferral(TransitionCounsellingCaseRequest $request, string $school, string $referral, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'referral', $referral, 'closed', $action);
    }

    public function cancelReferral(TransitionCounsellingCaseRequest $request, string $school, string $referral, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'referral', $referral, 'cancelled', $action);
    }

    public function indexEscalation(Request $request, string $school, CounsellingReferralAction $action): JsonResponse
    {
        return $this->index($request, $school, 'escalation', $action);
    }

    public function storeEscalation(StoreCounsellingReferralRequest $request, string $school, CounsellingReferralAction $action): JsonResponse
    {
        return $this->store($request, $school, 'escalation', $action);
    }

    public function showEscalation(Request $request, string $school, string $escalation, CounsellingReferralAction $action): JsonResponse
    {
        return $this->show($request, $school, 'escalation', $escalation, $action);
    }

    public function approveEscalation(TransitionCounsellingCaseRequest $request, string $school, string $escalation, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'escalation', $escalation, 'approved', $action);
    }

    public function rejectEscalation(TransitionCounsellingCaseRequest $request, string $school, string $escalation, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'escalation', $escalation, 'rejected', $action);
    }

    public function acceptEscalation(TransitionCounsellingCaseRequest $request, string $school, string $escalation, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'escalation', $escalation, 'accepted', $action);
    }

    public function closeEscalation(TransitionCounsellingCaseRequest $request, string $school, string $escalation, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'escalation', $escalation, 'closed', $action);
    }

    public function cancelEscalation(TransitionCounsellingCaseRequest $request, string $school, string $escalation, CounsellingReferralAction $action): JsonResponse
    {
        return $this->transition($request, $school, 'escalation', $escalation, 'cancelled', $action);
    }

    private function index(Request $request, string $school, string $type, CounsellingReferralAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $type));
    }

    private function store(StoreCounsellingReferralRequest $request, string $school, string $type, CounsellingReferralAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $type, $request->validated()), 201);
    }

    private function show(Request $request, string $school, string $type, string $id, CounsellingReferralAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $type, $id));
    }

    private function transition(TransitionCounsellingCaseRequest $request, string $school, string $type, string $id, string $target, CounsellingReferralAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $type, $id, $target, $request->string('reason')->toString()));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
