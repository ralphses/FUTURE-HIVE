<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Contexts\Platform\Domain\Models\ImportDuplicateFinding;
use App\Contexts\Platform\Domain\Models\ImportMapping;
use App\Contexts\Platform\Domain\Models\ImportStagedRow;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportDuplicateDetectionAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function detect(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.duplicates.manage');

        return DB::transaction(function () use ($actor, $import): array {
            $batch = $this->batch($import, true);
            $mapping = $this->mapping($batch);
            $rows = $batch->rows()->orderBy('row_number')->limit((int) config('imports.max_rows', 5000))->get();
            if ($rows->isEmpty() || $rows->contains(static fn (ImportStagedRow $row): bool => $row->status !== 'valid')) {
                throw ValidationException::withMessages(['import' => ['All mapped rows must pass validation before duplicate detection.']]);
            }

            ImportDuplicateFinding::query()->where('import_batch_id', $batch->getKey())->delete();
            $keys = [];
            foreach ($rows as $row) {
                foreach ($this->rowKeys($batch, $row) as $key) {
                    $keys[$key['type'].'|'.$key['value']][] = ['row' => $row, 'key' => $key];
                }
            }

            $findingCount = 0;
            foreach ($keys as $group) {
                if (count($group) < 2) {
                    continue;
                }
                foreach ($group as $entry) {
                    $other = collect($group)->first(fn (array $candidate): bool => $candidate['row']->getKey() !== $entry['row']->getKey());
                    $this->createFinding($batch, $entry['row'], $entry['key'], 'within_file', null, (int) $other['row']->row_number, 'within_file_duplicate');
                    $findingCount++;
                }
            }

            foreach ($rows as $row) {
                foreach ($this->existingCandidates($batch, $row) as $candidate) {
                    $this->createFinding($batch, $row, $candidate['key'], 'existing_record', $candidate['public_id'], null, 'existing_record_match');
                    $findingCount++;
                }
            }

            $this->audit->execute(new AuditEventData(
                action: 'import.duplicates.detected',
                subjectType: 'import_batch',
                subjectPublicId: (string) $batch->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.duplicates.manage'],
                metadata: ['mapping_version' => (int) $mapping->mapping_version, 'row_count' => $rows->count(), 'finding_count' => $findingCount],
                requiresSchoolContext: true,
            ));

            return $this->data($batch);
        });
    }

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $school, string $import): array
    {
        $this->authorize($actor, $school, 'imports.duplicates.read');

        return $this->data($this->batch($import));
    }

    /** @return array<string, mixed> */
    public function resolve(Authenticatable $actor, string $school, string $import, string $duplicate, string $resolution, ?string $candidatePublicId): array
    {
        $this->authorize($actor, $school, 'imports.duplicates.manage');

        return DB::transaction(function () use ($actor, $import, $duplicate, $resolution, $candidatePublicId): array {
            $batch = $this->batch($import, true);
            $finding = ImportDuplicateFinding::query()->where('import_batch_id', $batch->getKey())->where('public_id', $duplicate)->lockForUpdate()->first();
            if (! $finding instanceof ImportDuplicateFinding) {
                throw new ModelNotFoundException;
            }
            if ($batch->template_key === 'assessment_scores' && $resolution !== 'skip') {
                throw new ModelNotFoundException;
            }
            if ($resolution === 'link_existing' && ($finding->detection_source !== 'existing_record' || $candidatePublicId === null || $candidatePublicId !== $finding->candidate_public_id)) {
                throw new ModelNotFoundException;
            }
            if ($resolution !== 'link_existing' && $candidatePublicId !== null) {
                throw new ModelNotFoundException;
            }

            $finding->update(['resolution_state' => $resolution, 'resolved_by' => (int) $actor->getAuthIdentifier(), 'resolved_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit->execute(new AuditEventData(
                action: 'import.duplicate.resolved',
                subjectType: 'import_duplicate_finding',
                subjectPublicId: (string) $finding->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'imports.duplicates.manage'],
                stateTransition: ['from' => (string) $finding->getOriginal('resolution_state'), 'to' => $resolution],
                metadata: ['import_id' => (string) $batch->public_id, 'candidate_type' => (string) $finding->candidate_type],
                requiresSchoolContext: true,
            ));

            return $this->findingData($finding);
        });
    }

    /** @return array<string, mixed> */
    private function data(ImportBatch $batch): array
    {
        $findings = ImportDuplicateFinding::query()->where('import_batch_id', $batch->getKey())->orderBy('import_staged_row_id')->orderBy('id')->get();

        return [
            'import_id' => (string) $batch->public_id,
            'ready_for_dry_run' => $findings->every(static fn (ImportDuplicateFinding $finding): bool => $finding->resolution_state !== 'unresolved'),
            'summary' => ['total' => $findings->count(), 'unresolved' => $findings->where('resolution_state', 'unresolved')->count()],
            'findings' => $findings->map(fn (ImportDuplicateFinding $finding): array => $this->findingData($finding))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function findingData(ImportDuplicateFinding $finding): array
    {
        return [
            'id' => (string) $finding->public_id,
            'candidate_type' => (string) $finding->candidate_type,
            'candidate_public_id' => $finding->candidate_public_id,
            'candidate_row_number' => $finding->candidate_row_number === null ? null : (int) $finding->candidate_row_number,
            'detection_source' => (string) $finding->detection_source,
            'reason' => (string) $finding->reason_code,
            'resolution' => (string) $finding->resolution_state,
        ];
    }

    /** @return list<array{type: string, value: string}> */
    private function rowKeys(ImportBatch $batch, ImportStagedRow $row): array
    {
        $values = $row->normalized_cells ?? [];

        return match ((string) $batch->template_key) {
            'students' => $this->key($values, 'student', 'student_number'),
            'guardians' => $this->key($values, 'guardian_relationship', 'student_number', 'guardian_contact', 'relationship_type'),
            'staff' => array_merge($this->key($values, 'staff_profile', 'staff_number'), $this->key($values, 'staff_profile', 'membership_contact')),
            'assessment_scores' => $this->key($values, 'assessment_score', 'student_number', 'component_sequence'),
            default => [],
        };
    }

    /** @param array<string, string> $values
     * @return list<array{type: string, value: string}>
     */
    private function key(array $values, string $type, string ...$fields): array
    {
        $parts = [];
        foreach ($fields as $field) {
            if (($values[$field] ?? '') === '') {
                return [];
            }
            $parts[] = $values[$field];
        }

        return [['type' => $type, 'value' => implode('|', $parts)]];
    }

    /** @return list<array{key: array{type: string, value: string}, public_id: string}> */
    private function existingCandidates(ImportBatch $batch, ImportStagedRow $row): array
    {
        $schoolId = TenantContext::require()->schoolId;
        $values = $row->normalized_cells ?? [];
        $candidates = [];
        if ($batch->template_key === 'students' && ($values['student_number'] ?? '') !== '') {
            $id = DB::table('students')->where('school_id', $schoolId)->where('student_number', $values['student_number'])->value('public_id');
            if ($id !== null) {
                $candidates[] = ['key' => ['type' => 'student', 'value' => $values['student_number']], 'public_id' => (string) $id];
            }
        }
        if ($batch->template_key === 'guardians' && ($values['guardian_contact'] ?? '') !== '' && ($values['student_number'] ?? '') !== '') {
            $id = DB::table('student_guardian_relationships as sgr')->join('students as s', 's.id', '=', 'sgr.student_id')->join('guardian_profiles as gp', 'gp.id', '=', 'sgr.guardian_profile_id')->join('user_contacts as uc', 'uc.user_id', '=', 'gp.user_id')->where('sgr.school_id', $schoolId)->where('s.student_number', $values['student_number'])->where('uc.canonical_value', $values['guardian_contact'])->where('sgr.relationship_type', $values['relationship_type'] ?? '')->value('sgr.public_id');
            if ($id !== null) {
                $candidates[] = ['key' => ['type' => 'guardian_relationship', 'value' => implode('|', [$values['student_number'], $values['guardian_contact'], $values['relationship_type'] ?? ''])], 'public_id' => (string) $id];
            }
        }
        if ($batch->template_key === 'staff') {
            if (($values['staff_number'] ?? '') !== '') {
                $id = DB::table('staff_profiles')->where('school_id', $schoolId)->where('staff_number', $values['staff_number'])->value('public_id');
                if ($id !== null) {
                    $candidates[] = ['key' => ['type' => 'staff_profile', 'value' => $values['staff_number']], 'public_id' => (string) $id];
                }
            }
            if (($values['membership_contact'] ?? '') !== '') {
                $id = DB::table('staff_profiles as sp')->join('school_memberships as sm', 'sm.id', '=', 'sp.school_membership_id')->join('user_contacts as uc', 'uc.user_id', '=', 'sm.user_id')->where('sp.school_id', $schoolId)->where('uc.canonical_value', $values['membership_contact'])->value('sp.public_id');
                if ($id !== null) {
                    $candidates[] = ['key' => ['type' => 'staff_profile', 'value' => $values['membership_contact']], 'public_id' => (string) $id];
                }
            }
        }
        if ($batch->template_key === 'assessment_scores' && $batch->academic_assessment_instance_id !== null) {
            $componentId = DB::table('academic_assessment_policy_components as c')->join('academic_assessment_instances as i', 'i.academic_assessment_policy_version_id', '=', 'c.academic_assessment_policy_version_id')->where('i.id', $batch->academic_assessment_instance_id)->where('c.sequence', (int) ($values['component_sequence'] ?? 0))->value('c.id');
            $studentId = DB::table('students')->where('school_id', $schoolId)->where('student_number', $values['student_number'] ?? '')->value('id');
            if ($componentId !== null && $studentId !== null) {
                $id = DB::table('academic_assessment_score_entries')->where('school_id', $schoolId)->where('academic_assessment_instance_id', $batch->academic_assessment_instance_id)->where('student_id', $studentId)->where('academic_assessment_policy_component_id', $componentId)->where('status', '!=', 'missing')->value('public_id');
                if ($id !== null) {
                    $candidates[] = ['key' => ['type' => 'score_entry', 'value' => implode('|', [$values['student_number'], $values['component_sequence']])], 'public_id' => (string) $id];
                }
            }
        }

        return $candidates;
    }

    /** @param array{type: string, value: string} $key */
    private function createFinding(ImportBatch $batch, ImportStagedRow $row, array $key, string $source, ?string $candidatePublicId, ?int $candidateRowNumber, string $reason): void
    {
        ImportDuplicateFinding::query()->create([
            'import_batch_id' => $batch->getKey(),
            'import_staged_row_id' => $row->getKey(),
            'candidate_type' => $key['type'],
            'candidate_public_id' => $candidatePublicId,
            'candidate_row_number' => $candidateRowNumber,
            'detection_source' => $source,
            'match_key_hash' => hash_hmac('sha256', $key['type'].'|'.$key['value'], (string) config('app.key')),
            'reason_code' => $reason,
            'resolution_state' => 'unresolved',
            'request_id' => Context::get('request_id'),
        ]);
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
            throw ValidationException::withMessages(['mapping' => ['A complete import mapping is required before duplicate detection.']]);
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
