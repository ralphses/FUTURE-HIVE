<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicResultGate;
use App\Contexts\Academic\Domain\Models\AcademicResultRun;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicResultSnapshotAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array<string, mixed> */
    public function create(UserIdentity $actor, string $school, string $session, string $term, string $runId, ?string $supersedesSnapshotId = null): array
    {
        $this->assertPermission($actor, $school, 'academic.results.snapshots.create');
        $this->assertPublisher($actor);

        return DB::transaction(function () use ($actor, $school, $session, $term, $runId, $supersedesSnapshotId): array {
            [$academicTerm, $run] = $this->resolveRun($school, $session, $term, $runId);
            $gate = AcademicResultGate::query()->where('academic_result_run_id', $run->getKey())->lockForUpdate()->first();
            if (! $gate instanceof AcademicResultGate || $gate->status !== 'published' || $run->status !== 'completed' || (int) $run->not_computable_count !== 0) {
                throw ValidationException::withMessages(['result_run' => ['Only published, complete result runs can be snapshotted.']]);
            }

            $existing = AcademicResultSnapshot::query()->where('academic_result_run_id', $run->getKey())->where('source_fingerprint', $run->source_fingerprint)->first();
            if ($existing instanceof AcademicResultSnapshot) {
                return $this->snapshotData($existing->load('entries'));
            }

            $sources = AcademicAssessmentSubmission::query()->where('academic_term_id', $academicTerm->getKey())->where('status', 'approved')->with('instance.policy', 'instance.gradingScale')->get();
            $references = $sources->mapWithKeys(fn (AcademicAssessmentSubmission $source): array => [(string) $source->academic_subject_offering_id => ['policy_id' => (string) $source->instance?->policy?->public_id, 'grading_scale_id' => (string) $source->instance?->gradingScale?->public_id]])->all();
            $supersededSnapshot = $supersedesSnapshotId === null ? null : AcademicResultSnapshot::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $supersedesSnapshotId)->where('status', 'published')->firstOrFail();
            $snapshot = AcademicResultSnapshot::query()->create(['school_id' => TenantContext::require()->schoolId, 'academic_session_id' => $academicTerm->academic_session_id, 'academic_term_id' => $academicTerm->getKey(), 'academic_result_gate_id' => $gate->getKey(), 'academic_result_run_id' => $run->getKey(), 'supersedes_snapshot_id' => $supersededSnapshot?->getKey(), 'source_fingerprint' => $run->source_fingerprint, 'status' => 'published', 'policy_references' => array_values(array_map(static fn (array $reference): string => $reference['policy_id'], $references)), 'grading_scale_references' => array_values(array_map(static fn (array $reference): string => $reference['grading_scale_id'], $references)), 'published_by' => $actor->getKey(), 'published_at' => now(), 'request_id' => Context::get('request_id')]);
            $entryRows = $run->entries()->with(['student', 'offering.classArm'])->orderBy('student_id')->orderBy('academic_subject_offering_id')->get();
            foreach ($entryRows as $entry) {
                $reference = $references[(string) $entry->academic_subject_offering_id] ?? [];
                $policyId = $sources->firstWhere('academic_subject_offering_id', $entry->academic_subject_offering_id)?->instance?->academic_assessment_policy_version_id;
                $scaleId = $sources->firstWhere('academic_subject_offering_id', $entry->academic_subject_offering_id)?->instance?->academic_grading_scale_version_id;
                $enrollment = StudentEnrollment::query()->where('school_id', TenantContext::require()->schoolId)->where('student_id', $entry->student_id)->where('academic_term_id', $academicTerm->getKey())->where('academic_class_arm_id', $entry->offering?->academic_class_arm_id)->where('status', 'active')->first();
                $snapshot->entries()->create(['school_id' => TenantContext::require()->schoolId, 'academic_result_entry_id' => $entry->getKey(), 'student_id' => $entry->student_id, 'student_enrollment_id' => $enrollment?->getKey(), 'academic_subject_offering_id' => $entry->academic_subject_offering_id, 'academic_assessment_policy_version_id' => $policyId, 'academic_grading_scale_version_id' => $scaleId, 'status' => $entry->status, 'total_score' => $entry->total_score, 'total_max_marks' => $entry->total_max_marks, 'percentage' => $entry->percentage, 'grade' => $entry->grade, 'grade_label' => $entry->grade_label, 'is_passing' => $entry->is_passing, 'remark' => $entry->remark, 'error_code' => $entry->error_code]);
            }
            $this->audit->execute(new AuditEventData(schoolId: TenantContext::require()->schoolId, actorId: $actor->getKey(), action: 'academic.result_snapshot_created', subjectType: 'academic_result_snapshot', subjectPublicId: (string) $snapshot->public_id, stateTransition: ['from' => null, 'to' => 'published'], metadata: ['result_run_id' => (string) $run->public_id, 'entry_count' => $entryRows->count(), 'source_fingerprint' => $run->source_fingerprint]));

            return $this->snapshotData($snapshot->load('entries'));
        });
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor, string $school, string $session, string $term): LengthAwarePaginator
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.snapshots.read');

        return AcademicResultSnapshot::query()->where('academic_term_id', $academicTerm->getKey())->with(['run', 'gate'])->withCount('entries')->orderByDesc('created_at')->orderByDesc('id')->paginate(ApiResponse::perPage(request()->integer('per_page')))->through(fn (AcademicResultSnapshot $snapshot): array => $this->summary($snapshot));
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $snapshotId): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.snapshots.read');
        $snapshot = AcademicResultSnapshot::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $snapshotId)->firstOrFail();
        $entries = $this->visibleEntries($actor, $snapshot, $academicTerm)->get()->map(fn (AcademicResultSnapshotEntry $entry): array => $this->entryData($entry))->values()->all();

        return ['snapshot' => $this->summary($snapshot), 'entries' => $entries];
    }

    /** @return array<string, mixed> */
    public function student(UserIdentity $actor, string $school, string $session, string $term, string $snapshotId, string $studentId): array
    {
        [$academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.snapshots.read');
        $snapshot = AcademicResultSnapshot::query()->where('academic_term_id', $academicTerm->getKey())->where('public_id', $snapshotId)->firstOrFail();
        $entries = $this->visibleEntries($actor, $snapshot, $academicTerm)->whereHas('student', fn ($query) => $query->where('public_id', $studentId))->get()->map(fn (AcademicResultSnapshotEntry $entry): array => $this->entryData($entry))->values()->all();
        if ($entries === []) {
            throw new ModelNotFoundException;
        }

        return ['snapshot' => $this->summary($snapshot), 'entries' => $entries];
    }

    /** @return array{0: AcademicTerm, 1: AcademicResultRun} */
    private function resolveRun(string $school, string $session, string $term, string $runId): array
    {
        $context = TenantContext::require();
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        $run = AcademicResultRun::query()->where('school_id', $context->schoolId)->where('academic_term_id', $academicTerm?->getKey())->where('public_id', $runId)->first();
        if ($context->schoolPublicId !== $school || ! $academicTerm instanceof AcademicTerm || ! $run instanceof AcademicResultRun) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $run];
    }

    /** @return array{0: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $term)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $session))->first();
        if (! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm];
    }

    private function assertPermission(UserIdentity $actor, string $school, string $permission): void
    {
        if (! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function assertPublisher(UserIdentity $actor): void
    {
        $context = TenantContext::require();
        if (! SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['proprietor', 'principal'])))->exists()) {
            throw new ModelNotFoundException;
        }
    }

    /** @return Builder<AcademicResultSnapshotEntry> */
    private function visibleEntries(UserIdentity $actor, AcademicResultSnapshot $snapshot, AcademicTerm $term): Builder
    {
        $query = AcademicResultSnapshotEntry::query()->where('academic_result_snapshot_id', $snapshot->getKey())->with(['student', 'enrollment', 'offering.subject', 'policyVersion', 'gradingScale', 'sourceEntry']);
        $context = TenantContext::require();
        $leadership = SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer'])))->exists();
        if (! $leadership) {
            $query->whereHas('offering.teachingAssignments', fn ($builder) => $builder->where('teacher_id', $actor->getKey())->where('academic_term_id', $term->getKey())->where('status', 'active'));
        }

        return $query->orderBy('student_id')->orderBy('academic_subject_offering_id');
    }

    /** @return array<string, mixed> */
    private function summary(AcademicResultSnapshot $snapshot): array
    {
        return ['id' => (string) $snapshot->public_id, 'result_run_id' => (string) $snapshot->run?->public_id, 'gate_id' => (string) $snapshot->gate?->public_id, 'status' => (string) $snapshot->status, 'snapshot_version' => (int) $snapshot->snapshot_version, 'source_fingerprint' => (string) $snapshot->source_fingerprint, 'entry_count' => $snapshot->entries_count ?? $snapshot->entries()->count(), 'published_at' => CarbonImmutable::parse((string) $snapshot->published_at)->toISOString(), 'created_at' => $snapshot->created_at?->toISOString()];
    }

    /** @return array<string, mixed> */
    private function snapshotData(AcademicResultSnapshot $snapshot): array
    {
        $snapshot->loadMissing(['run', 'gate']);

        return ['snapshot' => $this->summary($snapshot), 'entries' => $snapshot->entries()->with(['student', 'enrollment', 'offering.subject', 'policyVersion', 'gradingScale', 'sourceEntry'])->orderBy('student_id')->orderBy('academic_subject_offering_id')->get()->map(fn (AcademicResultSnapshotEntry $entry): array => $this->entryData($entry))->values()->all()];
    }

    /** @return array<string, mixed> */
    private function entryData(AcademicResultSnapshotEntry $entry): array
    {
        return ['id' => (string) $entry->public_id, 'student_id' => (string) $entry->student?->public_id, 'student_name' => (string) $entry->student?->display_name, 'enrollment_id' => (string) $entry->enrollment?->public_id, 'offering_id' => (string) $entry->offering?->public_id, 'policy_id' => (string) $entry->policyVersion?->public_id, 'grading_scale_id' => (string) $entry->gradingScale?->public_id, 'source_result_entry_id' => (string) $entry->sourceEntry?->public_id, 'status' => (string) $entry->status, 'total_score' => $entry->total_score === null ? null : (float) $entry->total_score, 'total_max_marks' => $entry->total_max_marks === null ? null : (float) $entry->total_max_marks, 'percentage' => $entry->percentage === null ? null : (float) $entry->percentage, 'grade' => $entry->grade, 'grade_label' => $entry->grade_label, 'is_passing' => $entry->is_passing, 'remark' => $entry->remark, 'error_code' => $entry->error_code];
    }
}
