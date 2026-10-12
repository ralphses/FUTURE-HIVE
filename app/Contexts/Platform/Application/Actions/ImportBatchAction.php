<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Application\Services\ImportTemplateCatalog;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportBatchFile;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Files\CloudinaryAssetStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

final class ImportBatchAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly ImportTemplateCatalog $catalog,
        private readonly CloudinaryAssetStore $files,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(Authenticatable $actor, string $school): LengthAwarePaginator
    {
        $this->authorize($actor, $school, 'imports.read');

        return ImportBatch::query()
            ->latest('id')
            ->paginate(25)
            ->through(fn (ImportBatch $batch): array => $this->data($batch));
    }

    /** @return array<string, mixed> */
    public function upload(Authenticatable $actor, string $school, string $templateKey, string $version, UploadedFile $file, ?int $assessmentInstanceId = null, string $permission = 'imports.upload'): array
    {
        $this->authorize($actor, $school, $permission);
        if ($templateKey === 'assessment_scores' && $assessmentInstanceId === null) {
            throw new ModelNotFoundException;
        }
        $template = $this->catalog->get($templateKey);
        if ($template['version'] !== $version) {
            throw ValidationException::withMessages(['version' => ['The requested template version is not available.']]);
        }

        $format = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($format, $template['formats'], true)) {
            throw ValidationException::withMessages(['file' => ['The file format does not match the selected template.']]);
        }

        $checksum = hash_file('sha256', (string) $file->getRealPath());
        if ($checksum === false) {
            throw ValidationException::withMessages(['file' => ['The import file could not be fingerprinted.']]);
        }

        $reference = $this->files->store($file, $school);
        $batch = DB::transaction(function () use ($actor, $assessmentInstanceId, $checksum, $file, $format, $permission, $reference, $template, $templateKey, $version): ImportBatch {
            $batch = ImportBatch::query()->create([
                'academic_assessment_instance_id' => $assessmentInstanceId,
                'template_key' => $templateKey,
                'template_version' => $version,
                'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'format' => $format,
                'size' => (int) $file->getSize(),
                'checksum' => $checksum,
                'uploaded_by' => (int) $actor->getAuthIdentifier(),
                'status' => 'uploaded',
                'request_id' => Context::get('request_id'),
            ]);

            ImportBatchFile::query()->create([
                'import_batch_id' => $batch->getKey(),
                'cloudinary_public_id' => $reference->publicId,
                'cloudinary_resource_type' => $reference->resourceType,
                'format' => $reference->format,
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'import.uploaded',
                subjectType: 'import_batch',
                subjectPublicId: (string) $batch->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => $permission],
                metadata: ['template' => $template['key'], 'version' => $template['version'], 'format' => $format],
                requiresSchoolContext: true,
            ));

            return $batch;
        });

        try {
            $rows = $this->readRows($file, $format, $template);
            DB::transaction(function () use ($actor, $batch, $permission, $rows): void {
                foreach (array_chunk($rows, 250, true) as $chunk) {
                    foreach ($chunk as $rowNumber => $cells) {
                        ImportStagedRow::query()->create([
                            'import_batch_id' => $batch->getKey(),
                            'row_number' => $rowNumber,
                            'cells' => $cells,
                            'status' => 'pending',
                        ]);
                    }
                }

                $batch->update(['status' => 'staged', 'row_count' => count($rows)]);
                $this->audit->execute(new AuditEventData(
                    action: 'import.staged',
                    subjectType: 'import_batch',
                    subjectPublicId: (string) $batch->public_id,
                    schoolId: TenantContext::require()->schoolId,
                    actorId: (int) $actor->getAuthIdentifier(),
                    requestId: Context::get('request_id'),
                    authorizationContext: ['permission' => $permission],
                    metadata: ['row_count' => count($rows)],
                    requiresSchoolContext: true,
                ));
            });
        } catch (Throwable) {
            $batch->update(['status' => 'failed', 'failure_reason' => 'The import file could not be staged.']);
            $this->audit->execute(new AuditEventData(
                action: 'import.failed',
                subjectType: 'import_batch',
                subjectPublicId: (string) $batch->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => $permission],
                metadata: ['reason' => 'staging_failed'],
                requiresSchoolContext: true,
            ));

            throw ValidationException::withMessages(['file' => ['The import file could not be staged.']]);
        }

        return $this->data($batch->refresh());
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.read');
        $batch = ImportBatch::query()->where('public_id', $import)->first();
        if (! $batch instanceof ImportBatch) {
            throw new ModelNotFoundException;
        }

        return $this->data($batch);
    }

    /** @return array<string, mixed> */
    public function cancel(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.cancel');

        return DB::transaction(function () use ($actor, $import): array {
            $batch = ImportBatch::query()->where('public_id', $import)->lockForUpdate()->first();
            if (! $batch instanceof ImportBatch) {
                throw new ModelNotFoundException;
            }

            if (in_array($batch->status, ['uploaded', 'staged'], true)) {
                $batch->update(['status' => 'cancelled']);
                $this->audit->execute(new AuditEventData(
                    action: 'import.cancelled',
                    subjectType: 'import_batch',
                    subjectPublicId: (string) $batch->public_id,
                    schoolId: TenantContext::require()->schoolId,
                    actorId: (int) $actor->getAuthIdentifier(),
                    requestId: Context::get('request_id'),
                    authorizationContext: ['permission' => 'imports.cancel'],
                    stateTransition: ['from' => (string) $batch->getOriginal('status'), 'to' => 'cancelled'],
                    requiresSchoolContext: true,
                ));
            }

            return $this->data($batch->refresh());
        });
    }

    /** @return array<int, array<string, string>> */
    /** @param array{fields: list<array{name: string, required: bool, description: string, example: string}>} $template
     * @return array<int, array<string, string>>
     */
    private function readRows(UploadedFile $file, string $format, array $template): array
    {
        $reader = $format === 'csv' ? new Csv : new Xlsx;
        $reader->setReadDataOnly(true);
        $sheets = $reader->load((string) $file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $expectedHeaders = $this->catalog->headers($template);
        $headers = array_map(static fn (mixed $value): string => trim((string) $value), $sheets[0] ?? []);
        if ($headers !== $expectedHeaders) {
            throw ValidationException::withMessages(['file' => ['The file columns do not match the selected template.']]);
        }

        $rows = [];
        foreach (array_slice($sheets, 1, null, true) as $rowNumber => $row) {
            $cells = array_map(static fn (mixed $value): string => mb_substr(trim((string) $value), 0, 4000), array_values($row));
            if ($this->isBlankRow($cells)) {
                continue;
            }
            if (count($rows) >= (int) config('imports.max_rows', 5000)) {
                throw ValidationException::withMessages(['file' => ['The import file exceeds the maximum row limit.']]);
            }

            $rows[((int) $rowNumber) + 1] = array_combine($expectedHeaders, array_pad($cells, count($expectedHeaders), '')) ?: [];
        }

        return $rows;
    }

    /** @param list<string> $cells */
    private function isBlankRow(array $cells): bool
    {
        return count(array_filter($cells, static fn (string $value): bool => $value !== '')) === 0;
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @return array<string, mixed> */
    private function data(ImportBatch $batch): array
    {
        return [
            'id' => (string) $batch->public_id,
            'template' => (string) $batch->template_key,
            'version' => (string) $batch->template_version,
            'filename' => (string) $batch->original_filename,
            'format' => (string) $batch->format,
            'size' => (int) $batch->size,
            'status' => (string) $batch->status,
            'row_count' => $batch->row_count === null ? null : (int) $batch->row_count,
            'created_at' => $batch->created_at === null ? null : (string) $batch->created_at,
        ];
    }
}
