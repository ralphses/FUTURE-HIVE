<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class AcademicStudentResultHistoryAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor, string $school, string $session, string $term): LengthAwarePaginator
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term);
        $query = $this->visibleSnapshots($actor, $academicTerm)
            ->with(['run', 'gate'])
            ->withCount('entries')
            ->orderBy('published_at')
            ->orderBy('id');

        return $query->paginate(ApiResponse::perPage(request()->integer('per_page')))
            ->through(fn (AcademicResultSnapshot $snapshot): array => $this->snapshotSummary($snapshot));
    }

    /** @return LengthAwarePaginator<int, array{snapshot: array<string, mixed>, entries: array<int, array<string, mixed>>}> */
    public function student(UserIdentity $actor, string $school, string $session, string $term, string $student): LengthAwarePaginator
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term);
        $query = $this->visibleSnapshots($actor, $academicTerm)
            ->whereHas('entries.student', fn (Builder $builder): Builder => $builder->where('public_id', $student))
            ->with(['run', 'gate'])
            ->withCount('entries')
            ->orderBy('published_at')
            ->orderBy('id');

        $paginator = $query->paginate(ApiResponse::perPage(request()->integer('per_page')));
        if ($paginator->total() === 0) {
            throw new ModelNotFoundException;
        }

        return $paginator->through(function (AcademicResultSnapshot $snapshot) use ($actor, $academicTerm, $student): array {
            $entries = $this->visibleEntries($actor, $snapshot, $academicTerm)
                ->whereHas('student', fn (Builder $builder): Builder => $builder->where('public_id', $student))
                ->get()
                ->map(fn (AcademicResultSnapshotEntry $entry): array => $this->entryData($entry))
                ->values()
                ->all();

            return ['snapshot' => $this->snapshotSummary($snapshot), 'entries' => $entries];
        });
    }

    /** @return array{0: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'academic.results.history.read')) {
            throw new ModelNotFoundException;
        }

        $academicTerm = AcademicTerm::query()
            ->where('school_id', $context->schoolId)
            ->where('public_id', $term)
            ->whereHas('session', fn (Builder $builder): Builder => $builder->where('school_id', $context->schoolId)->where('public_id', $session))
            ->first();
        if (! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm];
    }

    /** @return Builder<AcademicResultSnapshot> */
    private function visibleSnapshots(UserIdentity $actor, AcademicTerm $term): Builder
    {
        $context = TenantContext::require();
        $query = AcademicResultSnapshot::query()
            ->where('school_id', $context->schoolId)
            ->where('academic_term_id', $term->getKey())
            ->where('status', 'published')
            ->whereHas('gate', fn (Builder $builder): Builder => $builder->where('status', 'published'))
            ->whereHas('entries', fn (Builder $builder): Builder => $builder->whereNull('error_code'));

        if (! $this->isLeadership($actor)) {
            $query->whereHas('entries.offering.teachingAssignments', fn (Builder $builder): Builder => $builder
                ->where('teacher_id', $actor->getKey())
                ->where('academic_term_id', $term->getKey())
                ->where('school_id', $context->schoolId)
                ->where('status', 'active'));
        }

        return $query;
    }

    /** @return Builder<AcademicResultSnapshotEntry> */
    private function visibleEntries(UserIdentity $actor, AcademicResultSnapshot $snapshot, AcademicTerm $term): Builder
    {
        $context = TenantContext::require();
        $query = AcademicResultSnapshotEntry::query()
            ->where('school_id', $context->schoolId)
            ->where('academic_result_snapshot_id', $snapshot->getKey())
            ->with(['student', 'enrollment', 'offering.subject', 'policyVersion', 'gradingScale', 'sourceEntry'])
            ->orderBy('student_id')
            ->orderBy('academic_subject_offering_id');
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
    private function snapshotSummary(AcademicResultSnapshot $snapshot): array
    {
        $hasSuccessor = $snapshot->supersedingSnapshots()->where('status', 'published')->exists();

        return [
            'id' => (string) $snapshot->public_id,
            'result_run_id' => (string) $snapshot->run?->public_id,
            'gate_id' => (string) $snapshot->gate?->public_id,
            'status' => (string) $snapshot->status,
            'snapshot_version' => (int) $snapshot->snapshot_version,
            'is_current' => ! $hasSuccessor,
            'policy_references' => $this->references($snapshot->policy_references),
            'grading_scale_references' => $this->references($snapshot->grading_scale_references),
            'entry_count' => $snapshot->entries_count ?? $snapshot->entries()->count(),
            'published_at' => CarbonImmutable::parse((string) $snapshot->published_at)->toISOString(),
            'created_at' => $snapshot->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function entryData(AcademicResultSnapshotEntry $entry): array
    {
        return [
            'id' => (string) $entry->public_id,
            'student_id' => (string) $entry->student?->public_id,
            'student_name' => (string) $entry->student?->display_name,
            'enrollment_id' => (string) $entry->enrollment?->public_id,
            'offering_id' => (string) $entry->offering?->public_id,
            'subject_name' => (string) $entry->offering?->subject?->name,
            'policy_id' => (string) $entry->policyVersion?->public_id,
            'grading_scale_id' => (string) $entry->gradingScale?->public_id,
            'source_result_entry_id' => (string) $entry->sourceEntry?->public_id,
            'status' => (string) $entry->status,
            'total_score' => $entry->total_score === null ? null : (float) $entry->total_score,
            'total_max_marks' => $entry->total_max_marks === null ? null : (float) $entry->total_max_marks,
            'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
            'grade' => $entry->grade,
            'grade_label' => $entry->grade_label,
            'is_passing' => $entry->is_passing,
            'remark' => $entry->remark,
            'error_code' => $entry->error_code,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function references(mixed $references): array
    {
        return is_array($references) ? array_values(array_map(static fn (mixed $reference): string => (string) $reference, $references)) : [];
    }
}
