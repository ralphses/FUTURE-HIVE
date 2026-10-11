<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportDryRunReport;
use App\Contexts\Platform\Domain\Models\ImportDuplicateFinding;
use App\Contexts\Platform\Domain\Models\ImportMapping;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportDryRunAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function generate(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.dry-run.generate');

        return DB::transaction(function () use ($actor, $import): array {
            $batch = $this->batch($import, true);
            $mapping = $this->mapping($batch);
            $rows = $batch->rows()->orderBy('row_number')->limit((int) config('imports.max_rows', 5000))->get();
            if ($rows->isEmpty() || $rows->contains(static fn (ImportStagedRow $row): bool => $row->status === 'pending')) {
                throw ValidationException::withMessages(['import' => ['A completed validation projection is required before dry-run generation.']]);
            }

            $findings = ImportDuplicateFinding::query()->where('import_batch_id', $batch->getKey())->orderBy('id')->get();
            $report = $this->buildReport($batch, $mapping, $rows, $findings);
            ImportDryRunReport::query()->where('import_batch_id', $batch->getKey())->delete();
            $stored = ImportDryRunReport::query()->create([
                'import_batch_id' => $batch->getKey(),
                'template_key' => (string) $batch->template_key,
                'template_version' => (string) $batch->template_version,
                'mapping_version' => (int) $mapping->mapping_version,
                'validation_snapshot_hash' => $this->validationHash($rows),
                'duplicate_snapshot_hash' => $this->duplicateHash($findings),
                'status' => $report['status'],
                'report' => $report,
                'generated_by' => (int) $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'import.dry_run.generated',
                subjectType: 'import_dry_run_report',
                subjectPublicId: (string) $stored->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.dry-run.generate'],
                metadata: ['import_id' => (string) $batch->public_id, 'status' => $report['status'], 'row_count' => $report['summary']['total']],
                requiresSchoolContext: true,
            ));

            return $this->data($stored);
        });
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.dry-run.read');
        $batch = $this->batch($import);
        $report = ImportDryRunReport::query()->where('import_batch_id', $batch->getKey())->latest('id')->first();
        if (! $report instanceof ImportDryRunReport) {
            throw new ModelNotFoundException;
        }

        return $this->data($report);
    }

    /** @return array<string, mixed> */
    private function data(ImportDryRunReport $report): array
    {
        return ['id' => (string) $report->public_id] + (array) $report->report;
    }

    /** @return array<string, mixed> */
    /**
     * @param  Collection<int, ImportStagedRow>  $rows
     * @param  Collection<int, ImportDuplicateFinding>  $findings
     * @return array<string, mixed>
     */
    private function buildReport(ImportBatch $batch, ImportMapping $mapping, Collection $rows, Collection $findings): array
    {
        $invalid = $rows->where('status', 'invalid')->count();
        $valid = $rows->where('status', 'valid')->count();
        $unresolved = $findings->where('resolution_state', 'unresolved')->count();
        $blocked = [];
        if ($invalid > 0) {
            $blocked[] = 'invalid_rows';
        }
        if ($unresolved > 0) {
            $blocked[] = 'unresolved_duplicates';
        }

        $plans = [];
        foreach ($rows as $row) {
            if ($row->status !== 'valid') {
                continue;
            }
            $rowFindings = $findings->where('import_staged_row_id', $row->getKey());
            $resolution = $rowFindings->contains('resolution_state', 'unresolved') ? 'blocked' : ($rowFindings->contains('resolution_state', 'skip') ? 'skip' : ($rowFindings->contains('resolution_state', 'link_existing') ? 'link_existing' : 'create_new'));
            $plans[] = array_filter([
                'row_number' => (int) $row->row_number,
                'record_type' => $this->recordType((string) $batch->template_key),
                'action' => $resolution,
                'candidate_ids' => $rowFindings->where('resolution_state', 'link_existing')->pluck('candidate_public_id')->filter()->values()->all(),
            ], static fn (mixed $value): bool => ! is_array($value) || $value !== []);
        }

        $missing = [];
        foreach ($rows as $row) {
            foreach (($row->error_metadata ?? []) as $error) {
                $key = $error['field'].'|'.$error['code'];
                $missing[$key] = ($missing[$key] ?? 0) + 1;
            }
        }

        $status = $blocked === [] ? 'ready' : 'blocked';

        return [
            'import_id' => (string) $batch->public_id,
            'mapping_version' => (int) $mapping->mapping_version,
            'status' => $status,
            'ready_for_commit' => $status === 'ready',
            'summary' => [
                'total' => $rows->count(),
                'valid' => $valid,
                'invalid' => $invalid,
                'unresolved' => $unresolved,
                'skipped' => $findings->where('resolution_state', 'skip')->count(),
                'create_new' => $findings->where('resolution_state', 'create_new')->count() + $rows->where('status', 'valid')->filter(fn (ImportStagedRow $row): bool => $findings->where('import_staged_row_id', $row->getKey())->isEmpty())->count(),
                'link_existing' => $findings->where('resolution_state', 'link_existing')->count(),
            ],
            'blockers' => $blocked,
            'validation_errors' => collect($missing)->map(fn (int $count, string $key): array => ['field' => explode('|', $key)[0], 'code' => explode('|', $key)[1], 'count' => $count])->values()->all(),
            'duplicate_summary' => [
                'total' => $findings->count(),
                'within_file' => $findings->where('detection_source', 'within_file')->count(),
                'existing_record' => $findings->where('detection_source', 'existing_record')->count(),
                'by_reason' => $findings->groupBy('reason_code')->map->count()->all(),
            ],
            'planned' => $plans,
        ];
    }

    private function recordType(string $template): string
    {
        return match ($template) {
            'students' => 'student',
            'guardians' => 'guardian_relationship',
            'staff' => 'staff_profile',
            default => 'import_record',
        };
    }

    /** @param Collection<int, ImportStagedRow> $rows */
    private function validationHash(Collection $rows): string
    {
        return hash('sha256', json_encode($rows->map(fn (ImportStagedRow $row): array => [(int) $row->row_number, (string) $row->status, $row->error_metadata])->values()->all(), JSON_THROW_ON_ERROR));
    }

    /** @param Collection<int, ImportDuplicateFinding> $findings */
    private function duplicateHash(Collection $findings): string
    {
        return hash('sha256', json_encode($findings->map(fn (ImportDuplicateFinding $finding): array => [(string) $finding->public_id, (string) $finding->resolution_state])->values()->all(), JSON_THROW_ON_ERROR));
    }

    private function batch(string $import, bool $lock = false): ImportBatch
    {
        $query = ImportBatch::query()->where('public_id', $import);
        if ($lock) {
            $query->lockForUpdate();
        }
        $batch = $query->first();
        if (! $batch instanceof ImportBatch || $batch->status !== 'staged') {
            throw new ModelNotFoundException;
        }

        return $batch;
    }

    private function mapping(ImportBatch $batch): ImportMapping
    {
        $mapping = ImportMapping::query()->where('import_batch_id', $batch->getKey())->latest('mapping_version')->first();
        if (! $mapping instanceof ImportMapping || $mapping->status !== 'complete') {
            throw ValidationException::withMessages(['mapping' => ['A complete import mapping is required before dry-run generation.']]);
        }

        return $mapping;
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }
}
