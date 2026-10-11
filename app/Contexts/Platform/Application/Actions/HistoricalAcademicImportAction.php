<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\HistoricalAcademicImport;
use App\Contexts\Platform\Domain\Models\HistoricalAcademicRecord;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportCommit;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Imports\HistoricalAcademicReferenceResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class HistoricalAcademicImportAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly ImportCommitAction $commits,
        private readonly HistoricalAcademicReferenceResolver $references,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function import(Authenticatable $actor, string $school, string $batchPublicId): array
    {
        $this->authorize($actor, $school, 'imports.historical-academic.manage');

        return DB::transaction(function () use ($actor, $batchPublicId): array {
            $batch = ImportBatch::query()->where('public_id', $batchPublicId)->lockForUpdate()->first();
            if (! $batch instanceof ImportBatch || $batch->template_key !== 'historical_academic_results' || $batch->status !== 'staged') {
                throw new ModelNotFoundException;
            }
            $commit = ImportCommit::query()->where('import_batch_id', $batch->getKey())->latest('id')->first();
            $existing = HistoricalAcademicImport::query()->where('import_batch_id', $batch->getKey())->first();
            if ($existing instanceof HistoricalAcademicImport) {
                return $this->data($existing->load('records'));
            }

            [, $rows] = $this->commits->readyInputs($batch);
            $historical = HistoricalAcademicImport::query()->create([
                'import_batch_id' => $batch->getKey(),
                'import_commit_id' => $commit?->getKey(),
                'template_key' => (string) $batch->template_key,
                'template_version' => (string) $batch->template_version,
                'status' => 'unverified',
                'created_by' => (int) $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $accepted = 0;
            $rejected = 0;
            $errors = [];
            foreach ($rows as $row) {
                $result = $this->references->resolve($row->normalized_cells ?? []);
                HistoricalAcademicRecord::query()->create([
                    'historical_academic_import_id' => $historical->getKey(),
                    'row_number' => (int) $row->row_number,
                    'student_id' => $result['student_id'],
                    'academic_session_id' => $result['academic_session_id'],
                    'academic_term_id' => $result['academic_term_id'],
                    'academic_subject_offering_id' => $result['academic_subject_offering_id'],
                    'academic_assessment_policy_version_id' => $result['academic_assessment_policy_version_id'],
                    'score' => $result['score'],
                    'max_marks' => $result['max_marks'],
                    'grade' => $result['grade'],
                    'remark' => $result['remark'],
                    'provenance_reference' => $result['provenance_reference'],
                    'status' => $result['error_code'] === null ? 'unverified' : 'rejected',
                    'error_code' => $result['error_code'],
                ]);
                if ($result['error_code'] === null) {
                    $accepted++;
                } else {
                    $rejected++;
                    $errors[$result['error_code']] = ($errors[$result['error_code']] ?? 0) + 1;
                }
            }
            $historical->forceFill(['status' => $rejected === 0 ? 'accepted' : 'rejected', 'counts' => ['total' => $rows->count(), 'accepted' => $accepted, 'rejected' => $rejected], 'error_summary' => $errors])->save();
            $this->audit->execute(new AuditEventData(
                action: 'historical_academic_import.completed',
                subjectType: 'historical_academic_import',
                subjectPublicId: (string) $historical->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.historical-academic.manage'],
                metadata: ['batch_id' => (string) $batch->public_id, 'accepted' => $accepted, 'rejected' => $rejected],
                requiresSchoolContext: true,
            ));

            return $this->data($historical->load('records'));
        });
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $batchPublicId): array
    {
        $this->authorize($actor, $school, 'imports.historical-academic.read');
        $batch = ImportBatch::query()->where('public_id', $batchPublicId)->first();
        $historical = $batch instanceof ImportBatch ? HistoricalAcademicImport::query()->where('import_batch_id', $batch->getKey())->first() : null;
        if (! $historical instanceof HistoricalAcademicImport) {
            throw new ModelNotFoundException;
        }

        return $this->data($historical->load('records'));
    }

    /** @return array<string, mixed> */
    private function data(HistoricalAcademicImport $historical): array
    {
        return ['id' => (string) $historical->public_id, 'status' => (string) $historical->status, 'counts' => $historical->counts ?? [], 'errors' => $historical->error_summary ?? [], 'rows' => $historical->records->map(fn (HistoricalAcademicRecord $record): array => ['row_number' => (int) $record->row_number, 'status' => (string) $record->status, 'error_code' => $record->error_code, 'id' => (string) $record->public_id])->values()->all()];
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }
}
