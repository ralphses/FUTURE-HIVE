<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\ImportCanonicalRecordWriter;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportCommit;
use App\Contexts\Platform\Domain\Models\ImportCommitRow;
use App\Contexts\Platform\Domain\Models\ImportDuplicateFinding;
use App\Contexts\Platform\Domain\Models\ImportRecoveryAttempt;
use App\Contexts\Platform\Domain\Models\ImportRecoveryRow;
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

final class ImportRecoveryAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly ImportCommitAction $commits,
        private readonly ImportCanonicalRecordWriter $canonicalRecords,
    ) {}

    /** @return array<string, mixed> */
    public function retry(Authenticatable $actor, string $school, string $import, string $confirmation): array
    {
        $this->authorize($actor, $school, 'imports.recovery.execute');
        if ($confirmation !== 'RETRY_FAILED') {
            throw ValidationException::withMessages(['confirm' => ['Explicit RETRY_FAILED confirmation is required.']]);
        }

        return DB::transaction(function () use ($actor, $import): array {
            $batch = $this->batch($import);
            $commit = ImportCommit::query()->where('import_batch_id', $batch->getKey())->lockForUpdate()->first();
            if (! $commit instanceof ImportCommit || ! in_array($commit->status, ['completed_with_errors', 'failed'], true)) {
                throw new ModelNotFoundException;
            }

            [$mapping, $rows, $findings, $report] = $this->commits->readyInputs($batch);
            $failedRows = $commit->rows()->where('status', 'failed')->orderBy('row_number')->get();
            if ($failedRows->isEmpty()) {
                throw ValidationException::withMessages(['recovery' => ['There are no failed rows available for recovery.']]);
            }

            $failedRowsHash = $this->failedRowsHash($failedRows);
            $latest = ImportRecoveryAttempt::query()->where('import_commit_id', $commit->getKey())->latest('attempt_number')->first();
            if ($latest instanceof ImportRecoveryAttempt && $latest->failed_rows_hash === $failedRowsHash) {
                return $this->data($latest->load('rows', 'commit'));
            }

            $attempt = ImportRecoveryAttempt::query()->create([
                'import_commit_id' => $commit->getKey(),
                'import_batch_id' => $batch->getKey(),
                'attempt_number' => ((int) ($latest instanceof ImportRecoveryAttempt ? $latest->attempt_number : 0)) + 1,
                'failed_rows_hash' => $failedRowsHash,
                'validation_snapshot_hash' => $this->commits->validationHash($rows),
                'duplicate_snapshot_hash' => $this->commits->duplicateHash($findings),
                'mapping_version' => (int) $mapping->mapping_version,
                'status' => 'running',
                'created_by' => (int) $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
                'started_at' => now(),
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'import.recovery.started',
                subjectType: 'import_recovery_attempt',
                subjectPublicId: (string) $attempt->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.recovery.execute'],
                metadata: ['commit_id' => (string) $commit->public_id, 'attempt_number' => (int) $attempt->attempt_number],
                requiresSchoolContext: true,
            ));

            $this->processRows($attempt, $commit, $batch, $actor, $failedRows, $findings);
            $commit->refresh()->load('rows');
            $failed = $commit->rows->where('status', 'failed')->count();
            $counts = [
                'total' => $commit->rows->count(),
                'retried' => $failedRows->count(),
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
            $attempt->forceFill([
                'status' => $failed === 0 ? 'completed' : 'completed_with_errors',
                'counts' => $counts,
                'error_summary' => $errors,
                'completed_at' => now(),
            ])->save();

            $this->audit->execute(new AuditEventData(
                action: 'import.recovery.completed',
                subjectType: 'import_recovery_attempt',
                subjectPublicId: (string) $attempt->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.recovery.execute'],
                stateTransition: ['from' => 'running', 'to' => (string) $attempt->status],
                metadata: ['commit_id' => (string) $commit->public_id, 'counts' => $counts],
                requiresSchoolContext: true,
            ));

            return $this->data($attempt->load('rows', 'commit'));
        });
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.recovery.read');
        $batch = $this->batch($import);
        $commit = ImportCommit::query()->where('import_batch_id', $batch->getKey())->first();
        if (! $commit instanceof ImportCommit) {
            throw new ModelNotFoundException;
        }
        $attempt = ImportRecoveryAttempt::query()->where('import_commit_id', $commit->getKey())->latest('attempt_number')->first();
        if (! $attempt instanceof ImportRecoveryAttempt) {
            throw new ModelNotFoundException;
        }

        return $this->data($attempt->load('rows', 'commit'));
    }

    /** @param Collection<int, ImportCommitRow> $failedRows
     * @param  Collection<int, ImportDuplicateFinding>  $findings
     */
    private function processRows(ImportRecoveryAttempt $attempt, ImportCommit $commit, ImportBatch $batch, Authenticatable $actor, Collection $failedRows, Collection $findings): void
    {
        foreach ($failedRows as $commitRow) {
            $staged = ImportStagedRow::query()->find($commitRow->import_staged_row_id);
            if (! $staged instanceof ImportStagedRow) {
                $result = ['status' => 'failed', 'action' => (string) $commitRow->action, 'id' => null, 'error' => 'source_row_unavailable'];
            } else {
                $rowFindings = $findings->where('import_staged_row_id', $staged->getKey());
                $linkFinding = $rowFindings->firstWhere('resolution_state', 'link_existing');
                $resolution = (string) $commitRow->action;
                try {
                    $result = DB::transaction(function () use ($actor, $batch, $staged, $resolution, $linkFinding): array {
                        if ($resolution === 'link_existing') {
                            $id = $this->canonicalRecords->verifyCandidate((string) $batch->template_key, (string) $linkFinding?->candidate_public_id);

                            return ['status' => 'completed', 'action' => 'link_existing', 'id' => $id, 'error' => null];
                        }
                        if ($batch->template_key !== 'students') {
                            return ['status' => 'failed', 'action' => 'create_new', 'id' => null, 'error' => 'create_not_supported'];
                        }
                        $values = $staged->normalized_cells ?? [];
                        $id = $this->canonicalRecords->createStudent($actor, [
                            'student_number' => (string) ($values['student_number'] ?? ''),
                            'display_name' => (string) ($values['display_name'] ?? ''),
                            'admission_date' => $values['admission_date'] ?? null,
                            'metadata' => $this->metadata($values['metadata'] ?? null),
                        ]);

                        return ['status' => 'completed', 'action' => 'create_new', 'id' => $id, 'error' => null];
                    });
                } catch (Throwable $exception) {
                    $result = ['status' => 'failed', 'action' => $resolution, 'id' => null, 'error' => $this->errorCode($exception)];
                }
            }

            ImportRecoveryRow::query()->create([
                'import_recovery_attempt_id' => $attempt->getKey(),
                'import_commit_row_id' => $commitRow->getKey(),
                'row_number' => (int) $commitRow->row_number,
                'record_type' => (string) $commitRow->record_type,
                'action' => $result['action'],
                'canonical_public_id' => $result['id'],
                'status' => $result['status'],
                'error_code' => $result['error'],
            ]);

            if ($result['status'] === 'completed') {
                $commitRow->forceFill(['status' => 'completed', 'canonical_public_id' => $result['id'], 'error_code' => null])->save();
            }
        }
    }

    /** @param Collection<int, ImportCommitRow> $rows */
    private function failedRowsHash(Collection $rows): string
    {
        return hash('sha256', json_encode($rows->map(fn (ImportCommitRow $row): array => [(int) $row->row_number, (string) $row->action, (string) $row->error_code])->values()->all(), JSON_THROW_ON_ERROR));
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
        return $exception instanceof ModelNotFoundException ? 'candidate_unavailable' : 'recovery_row_failed';
    }

    private function batch(string $import): ImportBatch
    {
        $batch = ImportBatch::query()->where('public_id', $import)->first();
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

    /** @return array<string, mixed> */
    private function data(ImportRecoveryAttempt $attempt): array
    {
        $attempt->loadMissing('commit', 'rows');

        return [
            'id' => (string) $attempt->public_id,
            'commit_id' => (string) $attempt->commit?->public_id,
            'status' => (string) $attempt->status,
            'attempt_number' => (int) $attempt->attempt_number,
            'counts' => $attempt->counts ?? [],
            'errors' => $attempt->error_summary ?? [],
            'rows' => $attempt->rows->map(fn (ImportRecoveryRow $row): array => [
                'row_number' => (int) $row->row_number,
                'record_type' => (string) $row->record_type,
                'action' => (string) $row->action,
                'status' => (string) $row->status,
                'canonical_id' => $row->canonical_public_id,
                'error_code' => $row->error_code,
            ])->values()->all(),
        ];
    }
}
