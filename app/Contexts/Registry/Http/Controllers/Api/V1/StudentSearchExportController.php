<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Http\Controllers\Api\V1;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Application\Actions\StudentSearchExportAction;
use App\Contexts\Registry\Http\Requests\ExportStudentsRequest;
use App\Contexts\Registry\Http\Requests\SearchStudentsRequest;
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

final class StudentSearchExportController
{
    #[Endpoint(
        title: 'Search students',
        description: 'Searches the selected school’s student registry using safe filters and returns a paginated list. Only the trusted school context determines which records are visible.',
    )]
    #[QueryParameter(name: 'q', description: 'Optional student number or display-name search text.', type: 'string', infer: false, example: 'STU-001')]
    #[QueryParameter(name: 'status', description: 'Optional lifecycle filter: pending, active, withdrawn or archived.', type: 'string', infer: false, example: 'active')]
    #[QueryParameter(name: 'term_id', description: 'Optional public academic-term identifier for the current placement filter.', type: 'string', format: 'uuid', infer: false, example: '018f4b5e-7f7a-7d9c-8a74-0c2e3a4e5f67')]
    #[QueryParameter(name: 'class_arm_id', description: 'Optional public class-arm identifier for the current placement filter.', type: 'string', format: 'uuid', infer: false, example: '018f4b5e-7f7a-7d9c-8a74-0c2e3a4e5f68')]
    #[QueryParameter(name: 'page', description: 'Page number, starting at 1.', type: 'integer', infer: false, example: 1, default: 1)]
    #[QueryParameter(name: 'per_page', description: 'Number of students per page. The server applies the standard maximum.', type: 'integer', infer: false, example: 25, default: 25)]
    #[Response(status: 200, description: 'A paginated list of safe student registry fields.', type: 'array')]
    public function search(SearchStudentsRequest $request, string $school, StudentSearchExportAction $action): JsonResponse
    {
        return ApiResponse::paginated($action->search($this->identity($request), $school, $request->validated()));
    }

    #[Endpoint(
        title: 'Export students',
        description: 'Exports the selected school’s safe student registry fields as CSV, PDF or XLSX. Export access is permission-gated and capped to protect synchronous requests.',
    )]
    #[QueryParameter(name: 'format', description: 'Export format: csv for data exchange, pdf for a readable report, or xlsx for spreadsheet workflows.', required: true, type: 'string', infer: false, example: 'csv')]
    #[QueryParameter(name: 'q', description: 'Optional student number or display-name search text.', type: 'string', infer: false, example: 'STU-001')]
    #[QueryParameter(name: 'status', description: 'Optional lifecycle filter: pending, active, withdrawn or archived.', type: 'string', infer: false, example: 'active')]
    #[QueryParameter(name: 'term_id', description: 'Optional public academic-term identifier for the current placement filter.', type: 'string', format: 'uuid', infer: false, example: '018f4b5e-7f7a-7d9c-8a74-0c2e3a4e5f67')]
    #[QueryParameter(name: 'class_arm_id', description: 'Optional public class-arm identifier for the current placement filter.', type: 'string', format: 'uuid', infer: false, example: '018f4b5e-7f7a-7d9c-8a74-0c2e3a4e5f68')]
    #[Response(status: 200, description: 'A downloadable CSV export.', mediaType: 'text/csv', type: 'string', format: 'binary')]
    #[Response(status: 200, description: 'A downloadable PDF export.', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[Response(status: 200, description: 'A downloadable Excel workbook.', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function export(ExportStudentsRequest $request, string $school, StudentSearchExportAction $action): StreamedResponse|JsonResponse
    {
        $rows = $action->export($this->identity($request), $school, $request->validated());
        $format = $request->string('format')->toString();
        $filename = 'students-'.now()->format('Ymd-His');

        return match ($format) {
            'csv' => $this->csv($rows, $filename.'.csv'),
            'pdf' => $this->pdf($rows, $filename.'.pdf'),
            'xlsx' => $this->xlsx($rows, $filename.'.xlsx'),
            default => ApiResponse::error('VALIDATION_FAILED', 'The requested export format is not supported.', [], 422),
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Student ID', 'Student number', 'Display name', 'Status', 'Admission date', 'Term', 'Class arm']);
            foreach ($rows as $row) {
                fputcsv($handle, $this->flatRow($row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function pdf(array $rows, string $filename): StreamedResponse
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:10px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:4px;text-align:left}</style></head><body><h1>Student Registry</h1><table><thead><tr><th>Student ID</th><th>Student number</th><th>Display name</th><th>Status</th><th>Admission date</th><th>Term</th><th>Class arm</th></tr></thead><tbody>';
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
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Student ID', 'Student number', 'Display name', 'Status', 'Admission date', 'Term', 'Class arm'], null, 'A1');
        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($this->flatRow($row), null, 'A'.$rowNumber++);
        }
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param array<string, mixed> $row
     * @return list<string>
     */
    private function flatRow(array $row): array
    {
        return [(string) $row['id'], (string) $row['student_number'], (string) $row['display_name'], (string) $row['status'], (string) $row['admission_date'], (string) ($row['current_placement']['term'] ?? ''), (string) ($row['current_placement']['class_arm'] ?? '')];
    }

    private function identity(Request $request): UserIdentity
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);

        return $identity;
    }
}
