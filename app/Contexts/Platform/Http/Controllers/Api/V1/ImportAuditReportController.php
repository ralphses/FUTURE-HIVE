<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\ImportAuditReportAction;
use App\Contexts\Platform\Http\Requests\ImportReportRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Dompdf\Dompdf;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImportAuditReportController
{
    #[Endpoint(title: 'View import audit history', description: 'Lists safe, read-only events for every stage of an import in the selected school. Source cells, contacts, credentials, private URLs and internal identifiers are excluded.')]
    #[QueryParameter(name: 'page', description: 'Page number, starting at 1.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Number of audit events per page. The server applies the standard maximum.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'A paginated safe import audit history.', type: 'array')]
    public function audit(Request $request, string $school, string $import, ImportAuditReportAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->audit($this->identity($request), $school, $import));
    }

    #[Endpoint(title: 'Download an import audit report', description: 'Downloads a bounded CSV, PDF or Excel report of safe import audit events. The report is read-only and excludes source values, contacts, balances, credentials and private URLs.')]
    #[QueryParameter(name: 'format', description: 'Report format: csv for data exchange, pdf for a readable report, or xlsx for spreadsheet workflows.', required: true, type: 'string', infer: false, example: 'csv')]
    #[Response(status: 200, description: 'A downloadable import audit report.', mediaType: 'text/csv', type: 'string', format: 'binary')]
    public function report(ImportReportRequest $request, string $school, string $import, ImportAuditReportAction $action): StreamedResponse
    {
        $report = $action->report($this->identity($request), $school, $import);
        $format = (string) $request->validated('format');
        $filename = 'import-audit-'.(string) $report['import_id'].'-'.now()->format('Ymd-His');

        return match ($format) {
            'csv' => $this->csv($report['rows'], $filename.'.csv'),
            'pdf' => $this->pdf($report['rows'], $filename.'.pdf'),
            'xlsx' => $this->xlsx($report['rows'], $filename.'.xlsx'),
            default => throw new InvalidArgumentException('Unsupported report format.'),
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $this->headers());
            foreach ($rows as $row) {
                fputcsv($handle, $this->flatRow($row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function pdf(array $rows, string $filename): StreamedResponse
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px;text-align:left}</style></head><body><h1>Import Audit Report</h1><table><thead><tr>'.collect($this->headers())->map(fn (string $header): string => '<th>'.e($header).'</th>')->implode('').'</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'.collect($this->flatRow($row))->map(fn (string $value): string => '<td>'.e($value).'</td>')->implode('').'</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response()->streamDownload(fn (): int => print $pdf->output(), $filename, ['Content-Type' => 'application/pdf']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function xlsx(array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($this->headers(), null, 'A1');
        $rowNumber = 2;
        foreach ($rows as $row) {
            $spreadsheet->getActiveSheet()->fromArray($this->flatRow($row), null, 'A'.$rowNumber++);
        }
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Event ID', 'Stage', 'Action', 'Subject type', 'Subject ID', 'Reason', 'State transition', 'Actor ID', 'Request ID', 'Created at'];
    }

    /** @param array<string, mixed> $row
     * @return list<string>
     */
    private function flatRow(array $row): array
    {
        return [(string) $row['id'], (string) $row['stage'], (string) $row['action'], (string) $row['subject_type'], (string) ($row['subject_id'] ?? ''), (string) ($row['reason'] ?? ''), json_encode($row['state_transition'], JSON_THROW_ON_ERROR), (string) ($row['actor_id'] ?? ''), (string) ($row['request_id'] ?? ''), (string) ($row['created_at'] ?? '')];
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
