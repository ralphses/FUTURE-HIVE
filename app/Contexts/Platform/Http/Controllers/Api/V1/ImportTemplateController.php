<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportTemplateAction;
use App\Contexts\Platform\Application\Services\ImportTemplateCatalog;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImportTemplateController
{
    #[Endpoint(title: 'List import templates', description: 'Lists versioned CSV and Excel templates available to authorized school administrators. Templates describe future imports and do not create or change school records.')]
    #[Response(status: 200, description: 'The available import templates and their field definitions.', type: 'array')]
    public function index(Request $request, string $school, ImportTemplateAction $action): JsonResponse
    {
        return ApiResponse::data(['templates' => $action->list($this->identity($request), $school)]);
    }

    #[Endpoint(title: 'Download an import template', description: 'Downloads a stable fictional-example template for a future student, guardian or staff import. The selected school context authorizes access; this download does not upload or mutate records.')]
    #[QueryParameter(name: 'format', description: 'The file format: csv for plain data exchange or xlsx for spreadsheet workflows.', required: true, type: 'string', infer: false, example: 'csv')]
    #[Response(status: 200, description: 'The requested CSV template.', mediaType: 'text/csv', type: 'string', format: 'binary')]
    #[Response(status: 200, description: 'The requested XLSX template.', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function download(Request $request, string $school, string $template, ImportTemplateAction $action, ImportTemplateCatalog $catalog): StreamedResponse|JsonResponse
    {
        $format = $request->string('format')->lower()->toString();
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return ApiResponse::error('VALIDATION_FAILED', 'The requested template format is not supported.', [], 422);
        }

        $definition = $action->download($this->identity($request), $school, $template, $format);
        $filename = $catalog->filename($definition, $format);
        $headers = $catalog->headers($definition);
        $example = $catalog->example($definition);

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers, $example): void {
                $handle = fopen('php://output', 'wb');
                fputcsv($handle, $headers);
                fputcsv($handle, $example);
                fclose($handle);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headers, $example], null, 'A1');
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(static function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
