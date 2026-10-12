<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultGateAction;
use App\Contexts\Academic\Http\Requests\RejectAcademicResultRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicResultGateController
{
    #[Endpoint(title: 'View result approval status', description: 'Shows the safe approval and publication gate for a computed result run. It does not expose raw scores or create a report snapshot.')]
    #[Response(status: 200, description: 'Result gate status.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $run, AcademicResultGateAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $run));
    }

    #[Endpoint(title: 'Approve result run', description: 'Approves a complete computed result run for the publication gate. Source academic records remain unchanged.')]
    #[Response(status: 200, description: 'Approved result gate.', type: 'array')]
    public function approve(Request $request, string $school, string $session, string $term, string $run, AcademicResultGateAction $action): JsonResponse
    {
        return ApiResponse::data($action->approve($this->identity($request), $school, $session, $term, $run));
    }

    #[Endpoint(title: 'Reject result run', description: 'Rejects a result run with a bounded reason so it cannot be published until a later approved workflow changes its state.')]
    #[Response(status: 200, description: 'Rejected result gate.', type: 'array')]
    public function reject(RejectAcademicResultRequest $request, string $school, string $session, string $term, string $run, AcademicResultGateAction $action): JsonResponse
    {
        return ApiResponse::data($action->reject($this->identity($request), $school, $session, $term, $run, $request->string('reason')->trim()->toString()));
    }

    #[Endpoint(title: 'Publish result run', description: 'Publishes an approved result run through the server-controlled gate. This does not create immutable report snapshots or student/guardian delivery.')]
    #[Response(status: 200, description: 'Published result gate.', type: 'array')]
    public function publish(Request $request, string $school, string $session, string $term, string $run, AcademicResultGateAction $action): JsonResponse
    {
        return ApiResponse::data($action->publish($this->identity($request), $school, $session, $term, $run));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
