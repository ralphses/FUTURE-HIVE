<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Http\Controllers\Api\V1;

use App\Contexts\Finance\Application\Actions\FinanceReportAction;
use App\Contexts\Finance\Http\Requests\FinanceReportRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceReportController
{
    #[Endpoint(title: 'List collections report', description: 'Lists safe, derived collections totals for the trusted school scope.')]
    public function collections(FinanceReportRequest $request, string $school, FinanceReportAction $action): JsonResponse
    {
        return ApiResponse::data($action->report($this->identity($request), $school, 'collections', $request->validated()));
    }

    #[Endpoint(title: 'List arrears report', description: 'Lists safe outstanding invoice aging rows for the trusted school scope.')]
    public function arrears(FinanceReportRequest $request, string $school, FinanceReportAction $action): JsonResponse
    {
        return ApiResponse::data($action->report($this->identity($request), $school, 'arrears', $request->validated()));
    }

    #[Endpoint(title: 'List fee-plan report', description: 'Lists safe fee-plan and category breakdowns for the trusted school scope.')]
    public function feePlans(FinanceReportRequest $request, string $school, FinanceReportAction $action): JsonResponse
    {
        return ApiResponse::data($action->report($this->identity($request), $school, 'fee-plans', $request->validated()));
    }

    #[Endpoint(title: 'Export finance report', description: 'Downloads a bounded CSV, PDF or Excel bursar report without persisting a report snapshot.')]
    #[QueryParameter(name: 'format', description: 'Export format: csv, pdf or xlsx.', required: true, type: 'string', infer: false, example: 'csv')]
    #[Response(status: 200, description: 'A safe finance report export.', mediaType: 'text/csv', type: 'string', format: 'binary')]
    public function export(FinanceReportRequest $request, string $school, FinanceReportAction $action): StreamedResponse
    {
        $format = (string) $request->validated('format');
        $reportKind = (string) ($request->validated('report') ?? 'collections');
        $report = $action->export($this->identity($request), $school, $reportKind, $request->validated());
        $filename = 'finance-report-'.$reportKind.'-'.now()->format('Ymd-His');
        $headers = ['Currency', 'Invoiced minor units', 'Adjusted minor units', 'Allocated minor units', 'Outstanding minor units', 'Invoice count'];
        $rows = [];
        if ($reportKind === 'collections') {
            $headers = ['Currency', 'Invoiced minor units', 'Adjusted minor units', 'Allocated minor units', 'Outstanding minor units', 'Invoice count'];
            $rows = array_map(fn (array $row): array => [(string) $row['currency'], (string) $row['invoiced_minor_units'], (string) $row['adjusted_minor_units'], (string) $row['allocated_minor_units'], (string) $row['outstanding_minor_units'], (string) $row['invoice_count']], $report['rows']);
        } elseif ($reportKind === 'arrears') {
            $headers = ['Invoice ID', 'Invoice number', 'Currency', 'Outstanding minor units', 'Due date', 'Aging bucket', 'Issues'];
            $rows = array_map(fn (array $row): array => [(string) $row['invoice_id'], (string) $row['invoice_number'], (string) $row['currency'], (string) $row['outstanding_minor_units'], (string) $row['due_date'], (string) $row['aging_bucket'], json_encode($row['issues'], JSON_THROW_ON_ERROR)], $report['rows']);
        } else {
            $headers = ['Fee plan ID', 'Fee plan name', 'Category code', 'Category name', 'Currency', 'Invoiced minor units', 'Invoice count'];
            $rows = array_map(fn (array $row): array => [(string) $row['fee_plan_id'], (string) $row['fee_plan_name'], (string) $row['category_code'], (string) $row['category_name'], (string) $row['currency'], (string) $row['invoiced_minor_units'], (string) $row['invoice_count']], $report['rows']);
        }

        return match ($format) {
            'csv' => $this->csv($headers, $rows, $filename.'.csv'),
            'pdf' => $this->pdf($headers, $rows, $filename.'.pdf'),
            'xlsx' => $this->xlsx($headers, $rows, $filename.'.xlsx'),
            default => abort(422, 'Unsupported export format.'),
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function csv(array $headers, array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function pdf(array $headers, array $rows, string $filename): StreamedResponse
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px;text-align:left}</style></head><body><h1>Bursar Collections Report</h1><table><thead><tr>'.collect($headers)->map(fn (string $header): string => '<th>'.e($header).'</th>')->implode('').'</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'.implode('', array_map(fn (string $value): string => '<td>'.e($value).'</td>', $row)).'</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response()->streamDownload(fn (): int => print $pdf->output(), $filename, ['Content-Type' => 'application/pdf']);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function xlsx(array $headers, array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($headers, null, 'A1');
        $rowNumber = 2;
        foreach ($rows as $row) {
            $spreadsheet->getActiveSheet()->fromArray($row, null, 'A'.$rowNumber++);
        }
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
