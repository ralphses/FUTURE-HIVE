<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\CounsellingCaseAction;
use App\Contexts\Registry\Http\Requests\StoreCounsellingCaseNoteRequest;
use App\Contexts\Registry\Http\Requests\StoreCounsellingCaseRequest;
use App\Contexts\Registry\Http\Requests\TransitionCounsellingCaseRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CounsellingCaseController
{
    public function index(Request $request, string $school, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school));
    }

    public function store(StoreCounsellingCaseRequest $request, string $school, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    public function show(Request $request, string $school, string $case, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $case));
    }

    public function hold(TransitionCounsellingCaseRequest $request, string $school, string $case, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $case, 'on_hold', $request->string('reason')->toString()));
    }

    public function close(TransitionCounsellingCaseRequest $request, string $school, string $case, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->transition($this->identity($request), $school, $case, 'closed', $request->string('reason')->toString()));
    }

    public function notes(Request $request, string $school, string $case, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->notes($this->identity($request), $school, $case));
    }

    public function storeNote(StoreCounsellingCaseNoteRequest $request, string $school, string $case, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->createNote($this->identity($request), $school, $case, $request->validated()), 201);
    }

    public function showNote(Request $request, string $school, string $case, string $note, CounsellingCaseAction $action): JsonResponse
    {
        return ApiResponse::data($action->showNote($this->identity($request), $school, $case, $note));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
