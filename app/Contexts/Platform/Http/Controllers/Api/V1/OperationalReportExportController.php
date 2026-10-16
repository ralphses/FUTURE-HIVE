<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\OperationalReportExportAction;
use App\Contexts\Platform\Http\Requests\OperationalReportExportRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class OperationalReportExportController
{
    #[Endpoint(title: 'Export an operational report', description: 'Generates a bounded CSV, PDF or XLSX operational report from current tenant-scoped projections. The artifact is private, signed and excludes sensitive source data; OPS-007 does not send notifications.')]
    #[QueryParameter(name: 'report', description: 'Report projection: enrollment, attendance, academic or finance.', required: true, type: 'string', infer: false, example: 'attendance')]
    #[QueryParameter(name: 'format', description: 'Export format: csv, pdf or xlsx.', required: true, type: 'string', infer: false, example: 'csv')]
    #[Response(status: 200, description: 'A short-lived signed private download link.', type: 'array')]
    public function __invoke(OperationalReportExportRequest $request, string $school, OperationalReportExportAction $action): JsonResponse
    {
        $identity = $request->user();
        abort_unless(is_a($identity, 'App\\Contexts\\Identity\\Domain\\Models\\UserIdentity'), 401);

        return ApiResponse::data($action->execute($identity, $school, $request->validated()));
    }
}
