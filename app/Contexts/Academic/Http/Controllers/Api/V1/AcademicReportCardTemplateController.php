<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicReportCardTemplateAction;
use App\Contexts\Academic\Http\Requests\UpsertAcademicReportCardTemplateRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicReportCardTemplateController
{
    #[Endpoint(title: 'List report-card templates', description: 'Lists versioned school-specific report-card templates linked to an approved immutable result snapshot. This configuration endpoint does not render or deliver reports.')]
    #[Response(status: 200, description: 'Report-card templates.', type: 'array')]
    public function index(Request $request, string $school, string $session, string $term, AcademicReportCardTemplateAction $action): JsonResponse
    {
        return ApiResponse::data($action->index($this->identity($request), $school, $session, $term));
    }

    #[Endpoint(title: 'Create a report-card template', description: 'Creates a draft versioned report-card template with ordered visible sections and fields linked to a published result snapshot. Server ownership and lifecycle metadata are controlled by the application.')]
    #[Response(status: 201, description: 'Created report-card template.', type: 'array')]
    public function store(UpsertAcademicReportCardTemplateRequest $request, string $school, string $session, string $term, AcademicReportCardTemplateAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $session, $term, $request->validated()), 201);
    }

    #[Endpoint(title: 'View a report-card template', description: 'Shows one versioned report-card template, its approved snapshot reference and ordered section/field visibility configuration.')]
    #[Response(status: 200, description: 'Report-card template.', type: 'array')]
    public function show(Request $request, string $school, string $session, string $term, string $template, AcademicReportCardTemplateAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $session, $term, $template));
    }

    #[Endpoint(title: 'Update a draft report-card template', description: 'Replaces a draft template definition and its ordered sections/fields. Active and retired versions are immutable.')]
    #[Response(status: 200, description: 'Updated report-card template.', type: 'array')]
    public function update(UpsertAcademicReportCardTemplateRequest $request, string $school, string $session, string $term, string $template, AcademicReportCardTemplateAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $session, $term, $template, $request->validated()));
    }

    #[Endpoint(title: 'Activate a report-card template', description: 'Activates one draft template for a school/session/term when no other active version exists. Activation does not render or publish reports.')]
    #[Response(status: 200, description: 'Activated report-card template.', type: 'array')]
    public function activate(Request $request, string $school, string $session, string $term, string $template, AcademicReportCardTemplateAction $action): JsonResponse
    {
        return ApiResponse::data($action->activate($this->identity($request), $school, $session, $term, $template));
    }

    #[Endpoint(title: 'Retire a report-card template', description: 'Retires an active report-card template while retaining its historical definition and snapshot reference.')]
    #[Response(status: 200, description: 'Retired report-card template.', type: 'array')]
    public function retire(Request $request, string $school, string $session, string $term, string $template, AcademicReportCardTemplateAction $action): JsonResponse
    {
        return ApiResponse::data($action->retire($this->identity($request), $school, $session, $term, $template));
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
