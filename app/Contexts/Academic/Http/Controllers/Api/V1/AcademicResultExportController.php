<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Http\Controllers\Api\V1;

use App\Contexts\Academic\Application\Actions\AcademicResultExportAction;
use App\Contexts\Academic\Http\Requests\AcademicResultExportRequest;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Files\CloudinaryAssetStore;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;

final class AcademicResultExportController
{
    #[Endpoint(title: 'Export published results', description: 'Generates a bounded CSV or PDF export from a published immutable result snapshot and returns a short-lived application-signed private download link. Teachers see only their assigned offerings; leadership sees the trusted school scope.')]
    #[QueryParameter(name: 'format', description: 'Export format: csv or pdf.', required: true, type: 'string', infer: false, example: 'pdf')]
    #[QueryParameter(name: 'snapshot', description: 'Optional public published snapshot identifier. When omitted, the terminal current snapshot is used.', type: 'string', format: 'uuid', infer: false)]
    #[QueryParameter(name: 'student', description: 'Optional public student identifier for a permitted single-student export.', type: 'string', format: 'uuid', infer: false)]
    #[Response(status: 200, description: 'A short-lived signed private result-export download link.', type: 'array')]
    public function __invoke(AcademicResultExportRequest $request, string $school, string $session, string $term, AcademicResultExportAction $action, CloudinaryAssetStore $files): JsonResponse
    {
        $identity = $request->user();
        abort_unless($identity instanceof UserIdentity, 401);
        $filters = $request->validated();
        $export = $action->export($identity, $school, $session, $term, $filters);
        $format = (string) $filters['format'];
        $contents = $format === 'csv' ? $this->csv($export['rows']) : $this->pdf($export['rows'], $export['template_name']);
        $reference = $files->storeGenerated($contents, $school, $format);

        return ApiResponse::data(['export' => [
            'snapshot_id' => $export['snapshot']['id'],
            'snapshot_version' => $export['snapshot']['version'],
            'format' => $format,
            'filename' => 'results-'.$export['snapshot']['id'].'.'.$format,
            'download_url' => $files->temporaryDownloadUrl($reference),
            'expires_in' => (int) config('services.cloudinary.download_ttl', 300),
        ]]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, $this->headers());
        foreach ($rows as $row) {
            fputcsv($handle, $this->flatRow($row));
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return (string) $contents;
    }

    /** @param list<array<string, mixed>> $rows */
    private function pdf(array $rows, ?string $templateName): string
    {
        $title = e($templateName === null ? 'Academic Result Export' : $templateName);
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px;text-align:left}</style></head><body><h1>'.$title.'</h1><table><thead><tr>'.collect($this->headers())->map(fn (string $header): string => '<th>'.e($header).'</th>')->implode('').'</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'.collect($this->flatRow($row))->map(fn (string $value): string => '<td>'.e($value).'</td>')->implode('').'</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return $pdf->output();
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Student ID', 'Student number', 'Student name', 'Offering ID', 'Subject', 'Total score', 'Maximum marks', 'Percentage', 'Grade', 'Grade label', 'Passing', 'Remark'];
    }

    /** @param array<string, mixed> $row
     * @return list<string>
     */
    private function flatRow(array $row): array
    {
        return [(string) $row['student_id'], (string) $row['student_number'], (string) $row['student_name'], (string) $row['offering_id'], (string) $row['subject_name'], (string) ($row['total_score'] ?? ''), (string) ($row['total_max_marks'] ?? ''), (string) ($row['percentage'] ?? ''), (string) ($row['grade'] ?? ''), (string) ($row['grade_label'] ?? ''), $row['is_passing'] ? 'yes' : 'no', (string) ($row['remark'] ?? '')];
    }
}
