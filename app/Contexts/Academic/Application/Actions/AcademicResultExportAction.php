<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplate;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

final class AcademicResultExportAction
{
    private const MAX_EXPORT_ROWS = 5000;

    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $filters
     * @return array{snapshot: array<string, mixed>, template_name: string|null, rows: list<array<string, mixed>>}
     */
    public function export(UserIdentity $actor, string $school, string $session, string $term, array $filters): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term);
        $snapshot = $this->resolveSnapshot($academicTerm, $filters['snapshot'] ?? null);
        $entries = $this->visibleEntries($actor, $snapshot, $academicTerm, $filters['student'] ?? null)->get();

        if ($entries->isEmpty()) {
            throw new ModelNotFoundException;
        }
        if ($entries->count() > self::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages(['student' => ['The result export is too large. Narrow the scope before downloading it.']]);
        }

        $template = AcademicReportCardTemplate::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('academic_session_id', $academicSession->getKey())
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('status', 'active')
            ->first();

        $rows = $entries->map(fn (AcademicResultSnapshotEntry $entry): array => $this->row($entry))->values()->all();
        $this->audit->execute(new AuditEventData(
            action: 'academic.result_export.generated',
            subjectType: 'academic_result_snapshot',
            subjectPublicId: (string) $snapshot->public_id,
            schoolId: TenantContext::require()->schoolId,
            actorId: $actor->getKey(),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'academic.results.exports.read'],
            metadata: ['format' => $filters['format'], 'row_count' => count($rows)],
            requiresSchoolContext: true,
        ));

        return [
            'snapshot' => [
                'id' => (string) $snapshot->public_id,
                'version' => (int) $snapshot->snapshot_version,
                'published_at' => CarbonImmutable::parse((string) $snapshot->published_at)->toISOString(),
            ],
            'template_name' => $template?->name,
            'rows' => $rows,
        ];
    }

    /** @return array{0: AcademicSession, 1: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'academic.results.exports.read')) {
            throw new ModelNotFoundException;
        }

        $academicSession = AcademicSession::query()->where('school_id', $context->schoolId)->where('public_id', $session)->first();
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('academic_session_id', $academicSession?->getKey())->where('public_id', $term)->first();
        if (! $academicSession instanceof AcademicSession || ! $academicTerm instanceof AcademicTerm || $academicSession->status !== 'active' || $academicTerm->status !== 'active') {
            throw new ModelNotFoundException;
        }

        return [$academicSession, $academicTerm];
    }

    private function resolveSnapshot(AcademicTerm $term, ?string $snapshotId): AcademicResultSnapshot
    {
        $query = AcademicResultSnapshot::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('academic_term_id', $term->getKey())
            ->where('status', 'published')
            ->whereHas('gate', fn (Builder $builder): Builder => $builder->where('status', 'published'))
            ->whereHas('entries', fn (Builder $builder): Builder => $builder->whereNull('error_code'));

        if ($snapshotId !== null) {
            $query->where('public_id', $snapshotId);
        } else {
            $query->whereDoesntHave('supersedingSnapshots', fn (Builder $builder): Builder => $builder->where('status', 'published'));
        }

        $snapshot = $query->first();
        if (! $snapshot instanceof AcademicResultSnapshot) {
            throw new ModelNotFoundException;
        }

        return $snapshot;
    }

    /** @return Builder<AcademicResultSnapshotEntry> */
    private function visibleEntries(UserIdentity $actor, AcademicResultSnapshot $snapshot, AcademicTerm $term, ?string $studentId): Builder
    {
        $context = TenantContext::require();
        $query = AcademicResultSnapshotEntry::query()
            ->where('school_id', $context->schoolId)
            ->where('academic_result_snapshot_id', $snapshot->getKey())
            ->whereNull('error_code')
            ->with(['student', 'offering.subject'])
            ->orderBy('student_id')
            ->orderBy('academic_subject_offering_id');

        if ($studentId !== null) {
            $query->whereHas('student', fn (Builder $builder): Builder => $builder->where('public_id', $studentId));
        }

        if (! $this->isLeadership($actor)) {
            $query->whereHas('offering.teachingAssignments', fn (Builder $builder): Builder => $builder
                ->where('teacher_id', $actor->getKey())
                ->where('academic_term_id', $term->getKey())
                ->where('school_id', $context->schoolId)
                ->where('status', 'active'));
        }

        return $query;
    }

    private function isLeadership(UserIdentity $actor): bool
    {
        $context = TenantContext::require();

        return SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())
            ->whereHas('roleAssignments', fn (Builder $builder): Builder => $builder->whereNull('revoked_at')->whereHas('role', fn (Builder $role): Builder => $role->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer'])))->exists();
    }

    /** @return array<string, mixed> */
    private function row(AcademicResultSnapshotEntry $entry): array
    {
        return [
            'student_id' => (string) $entry->student?->public_id,
            'student_number' => (string) $entry->student?->student_number,
            'student_name' => (string) $entry->student?->display_name,
            'offering_id' => (string) $entry->offering?->public_id,
            'subject_name' => (string) $entry->offering?->subject?->name,
            'total_score' => $entry->total_score === null ? null : (float) $entry->total_score,
            'total_max_marks' => $entry->total_max_marks === null ? null : (float) $entry->total_max_marks,
            'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
            'grade' => $entry->grade,
            'grade_label' => $entry->grade_label,
            'is_passing' => $entry->is_passing,
            'remark' => $entry->remark,
        ];
    }
}
