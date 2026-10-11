<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\ImportCanonicalRecordWriter;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportCommit;
use App\Contexts\Platform\Domain\Models\ImportCommitRow;
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
use JsonException;
use Throwable;

final class ImportCommitAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly ImportCanonicalRecordWriter $canonicalRecords,
    ) {}

    /** @return array<string, mixed> */
    public function commit(Authenticatable $actor, string $school, string $import, string $confirmation): array
    {
        $this->authorize($actor, $school, 'imports.commit.generate');
        if ($confirmation !== 'COMMIT') {
            throw ValidationException::withMessages(['confirm' => ['Explicit COMMIT confirmation is required.']]);
        }

        return DB::transaction(function () use ($actor, $import): array {
            $batch = $this->batch($import, true);
            $existing = ImportCommit::query()->where('import_batch_id', $batch->getKey())->first();
            if ($existing instanceof ImportCommit) {
                return $this->data($existing->load('rows'));
            }

            [$mapping, $rows, $findings, $report] = $this->readyInputs($batch);
            $commit = ImportCommit::query()->create([
                'import_batch_id' => $batch->getKey(),
                'template_key' => (string) $batch->template_key,
                'template_version' => (string) $batch->template_version,
                'mapping_version' => (int) $mapping->mapping_version,
                'validation_snapshot_hash' => $this->validationHash($rows),
                'duplicate_snapshot_hash' => $this->duplicateHash($findings),
                'dry_run_report_id' => $report->getKey(),
                'status' => 'running',
                'created_by' => (int) $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
                'started_at' => now(),
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'import.commit.started',
                subjectType: 'import_commit',
                subjectPublicId: (string) $commit->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.commit.generate'],
                metadata: ['import_id' => (string) $batch->public_id, 'mapping_version' => (int) $mapping->mapping_version],
                requiresSchoolContext: true,
            ));

            $this->processRows($commit, $batch, $actor, $rows, $findings);
            $commit->refresh()->load('rows');
            $failed = $commit->rows->where('status', 'failed')->count();
            $counts = [
                'total' => $commit->rows->count(),
                'created' => $commit->rows->where('status', 'completed')->where('action', 'create_new')->count(),
                'linked' => $commit->rows->where('status', 'completed')->where('action', 'link_existing')->count(),
                'skipped' => $commit->rows->where('status', 'skipped')->count(),
                'failed' => $failed,
            ];
            $errors = $commit->rows->where('status', 'failed')->groupBy('error_code')->map->count()->all();
            $commit->forceFill([
                'status' => $failed === 0 ? 'completed' : 'completed_with_errors',
                'counts' => $counts,
                'error_summary' => $errors,
                'completed_at' => now(),
            ])->save();

            $this->audit->execute(new AuditEventData(
                action: 'import.commit.completed',
                subjectType: 'import_commit',
                subjectPublicId: (string) $commit->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.commit.generate'],
                stateTransition: ['from' => 'running', 'to' => (string) $commit->status],
                metadata: ['import_id' => (string) $batch->public_id, 'counts' => $counts],
                requiresSchoolContext: true,
            ));

            return $this->data($commit->load('rows'));
        });
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.commit.read');
        $batch = $this->batch($import);
        $commit = ImportCommit::query()->where('import_batch_id', $batch->getKey())->with('rows')->first();
        if (! $commit instanceof ImportCommit) {
            throw new ModelNotFoundException;
        }

        return $this->data($commit);
    }

    /** @return array{0: ImportMapping, 1: Collection<int, ImportStagedRow>, 2: Collection<int, ImportDuplicateFinding>, 3: ImportDryRunReport} */
    private function readyInputs(ImportBatch $batch): array
    {
        $mapping = ImportMapping::query()->where('import_batch_id', $batch->getKey())->latest('mapping_version')->first();
        $rows = $batch->rows()->orderBy('row_number')->limit((int) config('imports.max_rows', 5000))->get();
        $findings = ImportDuplicateFinding::query()->where('import_batch_id', $batch->getKey())->orderBy('id')->get();
        $report = ImportDryRunReport::query()->where('import_batch_id', $batch->getKey())->latest('id')->first();

        if (! $mapping instanceof ImportMapping || $mapping->status !== 'complete') {
            throw ValidationException::withMessages(['mapping' => ['A complete import mapping is required before commit.']]);
        }
        if ($rows->isEmpty() || $rows->contains(static fn (ImportStagedRow $row): bool => $row->status !== 'valid')) {
            throw ValidationException::withMessages(['validation' => ['All import rows must pass validation before commit.']]);
        }
        if ($findings->contains(static fn (ImportDuplicateFinding $finding): bool => $finding->resolution_state === 'unresolved')) {
            throw ValidationException::withMessages(['duplicates' => ['Every duplicate finding must have an explicit resolution before commit.']]);
        }
        if (! $report instanceof ImportDryRunReport || $report->status !== 'ready' || ! (bool) data_get($report->report, 'ready_for_commit')) {
            throw ValidationException::withMessages(['dry_run' => ['A current ready dry-run report is required before commit.']]);
        }
        if ((int) $report->mapping_version !== (int) $mapping->mapping_version
            || $report->validation_snapshot_hash !== $this->validationHash($rows)
            || $report->duplicate_snapshot_hash !== $this->duplicateHash($findings)) {
            throw ValidationException::withMessages(['dry_run' => ['The dry-run report is stale. Generate a new dry run before commit.']]);
        }

        return [$mapping, $rows, $findings, $report];
    }

    /** @param Collection<int, ImportStagedRow> $rows
     * @param  Collection<int, ImportDuplicateFinding>  $findings
     */
    private function processRows(ImportCommit $commit, ImportBatch $batch, Authenticatable $actor, Collection $rows, Collection $findings): void
    {
        foreach ($rows as $row) {
            if (ImportCommitRow::query()->where('import_commit_id', $commit->getKey())->where('row_number', $row->row_number)->exists()) {
                continue;
            }
            $rowFindings = $findings->where('import_staged_row_id', $row->getKey());
            $skipFinding = $rowFindings->firstWhere('resolution_state', 'skip');
            $linkFinding = $rowFindings->firstWhere('resolution_state', 'link_existing');
            $resolution = $skipFinding instanceof ImportDuplicateFinding
                ? 'skip'
                : ($linkFinding instanceof ImportDuplicateFinding ? 'link_existing' : 'create_new');
            $finding = $linkFinding;

            try {
                $result = DB::transaction(function () use ($batch, $actor, $row, $resolution, $finding): array {
                    if ($resolution === 'skip') {
                        return ['status' => 'skipped', 'action' => 'skip', 'id' => null, 'error' => null];
                    }
                    if ($resolution === 'link_existing') {
                        $id = $this->canonicalRecords->verifyCandidate((string) $batch->template_key, (string) $finding?->candidate_public_id);

                        return ['status' => 'completed', 'action' => 'link_existing', 'id' => $id, 'error' => null];
                    }
                    if ($batch->template_key !== 'students') {
                        return ['status' => 'failed', 'action' => 'create_new', 'id' => null, 'error' => 'create_not_supported'];
                    }
                    $values = $row->normalized_cells ?? [];
                    $metadata = $this->metadata($values['metadata'] ?? null);
                    $id = $this->canonicalRecords->createStudent($actor, [
                        'student_number' => (string) ($values['student_number'] ?? ''),
                        'display_name' => (string) ($values['display_name'] ?? ''),
                        'admission_date' => $values['admission_date'] ?? null,
                        'metadata' => $metadata,
                    ]);

                    return ['status' => 'completed', 'action' => 'create_new', 'id' => $id, 'error' => null];
                });
            } catch (Throwable $exception) {
                $result = ['status' => 'failed', 'action' => $resolution, 'id' => null, 'error' => $this->errorCode($exception)];
            }

            ImportCommitRow::query()->create([
                'import_commit_id' => $commit->getKey(),
                'import_staged_row_id' => $row->getKey(),
                'row_number' => (int) $row->row_number,
                'record_type' => $this->recordType((string) $batch->template_key),
                'action' => $result['action'],
                'canonical_public_id' => $result['id'],
                'status' => $result['status'],
                'error_code' => $result['error'],
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function metadata(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return 'domain_validation_failed';
        }
        if ($exception instanceof ModelNotFoundException) {
            return 'candidate_unavailable';
        }

        return 'commit_row_failed';
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

    /** @return array<string, mixed> */
    private function data(ImportCommit $commit): array
    {
        $commit->loadMissing('batch', 'rows');

        return [
            'id' => (string) $commit->public_id,
            'import_id' => (string) $commit->batch?->public_id,
            'template_key' => (string) $commit->template_key,
            'template_version' => (string) $commit->template_version,
            'mapping_version' => (int) $commit->mapping_version,
            'status' => (string) $commit->status,
            'counts' => $commit->counts ?? [],
            'errors' => $commit->error_summary ?? [],
            'rows' => $commit->rows->map(fn (ImportCommitRow $row): array => [
                'row_number' => (int) $row->row_number,
                'record_type' => (string) $row->record_type,
                'action' => (string) $row->action,
                'status' => (string) $row->status,
                'canonical_id' => $row->canonical_public_id,
                'error_code' => $row->error_code,
            ])->values()->all(),
        ];
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

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }
}
