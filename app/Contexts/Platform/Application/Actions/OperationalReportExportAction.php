<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Files\CloudinaryAssetStore;
use App\Support\OperationalReportingAction;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class OperationalReportExportAction
{
    private const MAX_EXPORT_ROWS = 5000;

    public function __construct(
        private readonly OperationalReportingAction $reports,
        private readonly SchoolPermissionChecker $permissions,
        private readonly CloudinaryAssetStore $files,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{export: array{report: string, format: string, filename: string, download_url: string, expires_in: int}}
     */
    public function execute(Authenticatable $actor, string $school, array $filters): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'operations.reports.export')) {
            throw new ModelNotFoundException;
        }

        $reportType = (string) $filters['report'];
        $report = $this->reports->execute($actor, $school, $reportType, $filters);
        $rows = $this->rows($report['report']);
        if (count($rows) > self::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages(['report' => ['The export is too large. Narrow the scope.']]);
        }

        $format = (string) $filters['format'];
        $contents = match ($format) {
            'csv' => $this->csv($rows),
            'pdf' => $this->pdf($reportType, $rows),
            'xlsx' => $this->xlsx($rows),
            default => throw new ModelNotFoundException,
        };
        $reference = $this->files->storeGenerated($contents, $school, $format);
        $this->audit->execute(new AuditEventData(
            action: 'operations.report_export.generated',
            subjectType: 'operational_report_projection',
            subjectPublicId: null,
            schoolId: $context->schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['report' => $reportType, 'format' => $format, 'row_count' => count($rows)],
            requiresSchoolContext: true,
        ));

        return ['export' => [
            'report' => $reportType,
            'format' => $format,
            'filename' => 'operational-'.$reportType.'-'.CarbonImmutable::now()->format('Ymd-His').'.'.$format,
            'download_url' => $this->files->temporaryDownloadUrl($reference),
            'expires_in' => (int) config('services.cloudinary.download_ttl', 300),
        ]];
    }

    /** @param array<string, mixed> $report
     * @return list<array{key: string, value: string|int|float|null}>
     */
    private function rows(array $report): array
    {
        $rows = [];
        foreach ($report as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $rows[] = ['key' => (string) $key, 'value' => is_bool($value) ? ($value ? 'true' : 'false') : $value];
            } elseif (is_array($value)) {
                $rows[] = ['key' => (string) $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR)];
            }
        }

        return $rows;
    }

    /** @param list<array{key: string, value: string|int|float|null}> $rows */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, ['Indicator', 'Value']);
        foreach ($rows as $row) {
            fputcsv($handle, [$row['key'], $row['value']]);
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return (string) $contents;
    }

    /** @param list<array{key: string, value: string|int|float|null}> $rows */
    private function pdf(string $reportType, array $rows): string
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:10px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:4px;text-align:left}</style></head><body><h1>'.e('Operational '.$reportType.' report').'</h1><table><thead><tr><th>Indicator</th><th>Value</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($row['key']).'</td><td>'.e((string) ($row['value'] ?? '')).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return $pdf->output();
    }

    /** @param list<array{key: string, value: string|int|float|null}> $rows */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(['Indicator', 'Value'], null, 'A1');
        foreach ($rows as $index => $row) {
            $spreadsheet->getActiveSheet()->fromArray([$row['key'], $row['value']], null, 'A'.($index + 2));
        }
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}
