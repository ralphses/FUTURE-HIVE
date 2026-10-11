<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Application\Services\ImportTemplateCatalog;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportMapping;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportMappingAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly ImportTemplateCatalog $catalog,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.mapping.read');
        $batch = $this->batch($import);
        if ($batch->status !== 'staged') {
            throw new ModelNotFoundException;
        }
        $mapping = $this->latest($batch);

        return [
            'import_id' => (string) $batch->public_id,
            'template' => (string) $batch->template_key,
            'version' => (string) $batch->template_version,
            'mapping' => $mapping === null ? null : $this->mappingData($mapping, $batch),
        ];
    }

    /** @param list<array{source: string, target: string}> $mappings
     * @return array<string, mixed>
     */
    public function replace(Authenticatable $actor, string $school, string $import, array $mappings): array
    {
        $this->authorize($actor, $school, 'imports.mapping.manage');

        return DB::transaction(function () use ($actor, $import, $mappings): array {
            $batch = ImportBatch::query()->where('public_id', $import)->lockForUpdate()->first();
            if (! $batch instanceof ImportBatch || $batch->status !== 'staged') {
                throw new ModelNotFoundException;
            }

            $template = $this->catalog->get((string) $batch->template_key);
            $normalized = $this->normalize($mappings, $template);
            $required = array_values(array_filter($template['fields'], static fn (array $field): bool => $field['required']));
            $targets = array_column($normalized, 'target');
            $missing = array_values(array_diff(array_column($required, 'name'), $targets));
            $status = $missing === [] ? 'complete' : 'draft';
            $version = ((int) ImportMapping::query()->where('import_batch_id', $batch->getKey())->max('mapping_version')) + 1;

            $mapping = ImportMapping::query()->create([
                'import_batch_id' => $batch->getKey(),
                'template_key' => $batch->template_key,
                'template_version' => $batch->template_version,
                'mapping_version' => $version,
                'mappings' => $normalized,
                'status' => $status,
                'created_by' => (int) $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'import.mapping.saved',
                subjectType: 'import_mapping',
                subjectPublicId: (string) $mapping->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.mapping.manage'],
                metadata: ['import_id' => (string) $batch->public_id, 'mapping_version' => $version, 'status' => $status, 'mapping_count' => count($normalized)],
                requiresSchoolContext: true,
            ));

            return [
                'import_id' => (string) $batch->public_id,
                'template' => (string) $batch->template_key,
                'version' => (string) $batch->template_version,
                'mapping' => $this->mappingData($mapping, $batch),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function preview(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.mapping.read');
        $batch = $this->batch($import);
        $mapping = $this->latest($batch);
        if ($mapping === null || $batch->status !== 'staged') {
            throw new ModelNotFoundException;
        }

        $rows = $batch->rows()->orderBy('row_number')->limit((int) config('imports.max_rows', 5000))->get();
        $previewRows = $rows->map(function (ImportStagedRow $row) use ($mapping): array {
            $values = [];
            foreach ($mapping->mappings as $item) {
                $target = $item['target'];
                $source = $item['source'];
                $value = $row->cells[$source] ?? '';
                $values[$target] = $this->safePreviewValue($target, (string) $value);
            }

            return ['row_number' => (int) $row->row_number, 'values' => $values];
        })->values()->all();

        return [
            'import_id' => (string) $batch->public_id,
            'mapping_version' => (int) $mapping->mapping_version,
            'status' => (string) $mapping->status,
            'rows' => $previewRows,
        ];
    }

    /** @return array<string, mixed> */
    private function mappingData(ImportMapping $mapping, ImportBatch $batch): array
    {
        $template = $this->catalog->get((string) $batch->template_key);
        $required = array_values(array_filter($template['fields'], static fn (array $field): bool => $field['required']));
        $targets = array_column($mapping->mappings, 'target');

        return [
            'id' => (string) $mapping->public_id,
            'version' => (int) $mapping->mapping_version,
            'status' => (string) $mapping->status,
            'mappings' => $mapping->mappings,
            'missing_required_fields' => array_values(array_diff(array_column($required, 'name'), $targets)),
        ];
    }

    /** @param list<array{source: string, target: string}> $mappings
     * @param  array{fields: list<array{name: string, required: bool, description: string, example: string}>}  $template
     * @return list<array{source: string, target: string}>
     */
    private function normalize(array $mappings, array $template): array
    {
        $sources = $this->catalog->headers($template);
        $targets = array_column($template['fields'], 'name');
        $seenSources = [];
        $seenTargets = [];

        foreach ($mappings as $mapping) {
            $source = trim($mapping['source']);
            $target = trim($mapping['target']);
            if (! in_array($source, $sources, true) || ! in_array($target, $targets, true)) {
                throw ValidationException::withMessages(['mappings' => ['Each mapping must use a source column and target field from the selected template.']]);
            }
            if (in_array($source, $seenSources, true) || in_array($target, $seenTargets, true)) {
                throw ValidationException::withMessages(['mappings' => ['Source columns and target fields may be mapped only once.']]);
            }
            $seenSources[] = $source;
            $seenTargets[] = $target;
        }

        return array_map(static fn (array $mapping): array => [
            'source' => trim($mapping['source']),
            'target' => trim($mapping['target']),
        ], $mappings);
    }

    private function safePreviewValue(string $target, string $value): string
    {
        return str_contains($target, 'contact') ? '[redacted]' : mb_substr($value, 0, 4000);
    }

    private function batch(string $import): ImportBatch
    {
        $batch = ImportBatch::query()->where('public_id', $import)->first();
        if (! $batch instanceof ImportBatch) {
            throw new ModelNotFoundException;
        }

        return $batch;
    }

    private function latest(ImportBatch $batch): ?ImportMapping
    {
        return ImportMapping::query()->where('import_batch_id', $batch->getKey())->latest('mapping_version')->first();
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }
}
