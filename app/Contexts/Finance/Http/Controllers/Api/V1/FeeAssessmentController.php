<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FeeAssessmentAction;
use App\Contexts\Finance\Http\Requests\StoreFeeAssessmentRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeeAssessmentController
{
    #[Endpoint(title: 'Generate fee assessment', description: 'Generates idempotent draft student obligations and invoice-line snapshots from an active school fee plan. No payments or ledger entries are created.')]
    #[Response(status: 201, description: 'Generated fee assessment.', type: 'array')]
    public function store(StoreFeeAssessmentRequest $request, string $school, string $session, string $term, FeeAssessmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->generate($this->identity($request), $school, $session, $term, $request->validated()), 201);
    }

    #[Endpoint(title: 'List fee assessments', description: 'Lists safe fee-assessment runs for the trusted academic term.')]
    public function index(Request $request, string $school, string $session, string $term, FeeAssessmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->list($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'View fee assessment', description: 'Returns counts and lifecycle metadata for a school fee assessment run.')]
    public function show(Request $request, string $school, string $session, string $term, string $assessment, FeeAssessmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $assessment));
    }

    #[Endpoint(title: 'Finalize fee assessment', description: 'Finalizes a draft fee assessment and issues its retained invoice snapshots. This does not accept payments or post ledger entries.')]
    public function finalize(Request $request, string $school, string $assessment, FeeAssessmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->finalize($this->identity($request), $school, $assessment));
    }

    #[Endpoint(title: 'List invoices', description: 'Lists safe school-scoped invoices generated from fee assessments.')]
    public function invoices(Request $request, string $school, FeeAssessmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->invoices($this->identity($request), $school, $request->integer('page', 1), ApiResponse::perPage($request->integer('per_page', 25))));
    }

    #[Endpoint(title: 'View invoice', description: 'Returns a safe invoice and immutable fee-plan line snapshots without payment or ledger data.')]
    public function invoice(Request $request, string $school, string $invoice, FeeAssessmentAction $action): JsonResponse
    {
        return ApiResponse::data($action->invoice($this->identity($request), $school, $invoice));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
