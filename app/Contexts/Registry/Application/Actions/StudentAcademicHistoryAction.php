<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class StudentAcademicHistoryAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return array{schools: list<array<string, mixed>>} */
    public function handle(UserIdentity $actor): array
    {
        $students = $this->students($actor);
        if ($students->isEmpty()) {
            throw new ModelNotFoundException;
        }

        $result = $students->map(fn (object $student): array => $this->schoolData($student))->values()->all();
        $this->audit($actor, $students->count(), null);

        return ['schools' => $result];
    }

    /** @return array{school: array<string, mixed>} */
    public function school(UserIdentity $actor, string $school): array
    {
        $student = $this->students($actor)->first(fn (object $candidate): bool => (string) $candidate->school_public_id === $school);
        if ($student === null) {
            throw new ModelNotFoundException;
        }

        $this->audit($actor, 1, $school);

        return ['school' => $this->schoolData($student)];
    }

    /** @return Collection<int, \stdClass> */
    private function students(UserIdentity $actor): Collection
    {
        return DB::table('students')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->join('school_memberships', function ($join) use ($actor): void {
                $join->on('school_memberships.school_id', '=', 'students.school_id')
                    ->where('school_memberships.user_id', '=', $actor->getKey())
                    ->where('school_memberships.status', '=', 'active');
            })
            ->join('membership_roles', function ($join): void {
                $join->on('membership_roles.school_membership_id', '=', 'school_memberships.id')->whereNull('membership_roles.revoked_at');
            })
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'membership_roles.role_id')->where('roles.key', '=', 'student');
            })
            ->where('students.user_id', $actor->getKey())
            ->where('students.status', 'active')
            ->where('schools.status', 'active')
            ->select(['students.id', 'students.school_id', 'students.public_id as student_public_id', 'students.student_number', 'students.display_name', 'schools.public_id as school_public_id', 'schools.name as school_name'])
            ->distinct()
            ->orderBy('students.school_id')
            ->orderBy('students.id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function schoolData(object $student): array
    {
        $snapshots = DB::table('academic_result_snapshots as snapshots')
            ->join('academic_result_gates as gates', 'gates.id', '=', 'snapshots.academic_result_gate_id')
            ->join('academic_sessions as sessions', 'sessions.id', '=', 'snapshots.academic_session_id')
            ->join('academic_terms as terms', 'terms.id', '=', 'snapshots.academic_term_id')
            ->where('snapshots.school_id', $student->school_id)
            ->where('snapshots.status', 'published')
            ->where('gates.status', 'published')
            ->whereExists(function ($query) use ($student): void {
                $query->selectRaw('1')->from('academic_result_snapshot_entries as entries')->whereColumn('entries.academic_result_snapshot_id', 'snapshots.id')->where('entries.student_id', $student->id)->whereNull('entries.error_code')->where('entries.status', 'computed');
            })
            ->select(['snapshots.id', 'snapshots.public_id', 'snapshots.academic_session_id', 'snapshots.academic_term_id', 'snapshots.snapshot_version', 'snapshots.published_at', 'sessions.public_id as session_public_id', 'sessions.name as session_name', 'terms.public_id as term_public_id', 'terms.name as term_name'])
            ->orderBy('snapshots.published_at')
            ->orderBy('snapshots.id')
            ->get()
            ->map(fn (object $snapshot): array => $this->snapshotData($snapshot, $student))
            ->values()
            ->all();

        return [
            'school' => ['id' => (string) $student->school_public_id, 'name' => (string) $student->school_name],
            'student' => ['id' => (string) $student->student_public_id, 'student_number' => (string) $student->student_number, 'display_name' => (string) $student->display_name],
            'history' => $snapshots,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotData(object $snapshot, object $student): array
    {
        $placement = DB::table('student_enrollments')
            ->leftJoin('academic_levels', 'academic_levels.id', '=', 'student_enrollments.academic_level_id')
            ->leftJoin('academic_sections', 'academic_sections.id', '=', 'student_enrollments.academic_section_id')
            ->leftJoin('academic_class_arms', 'academic_class_arms.id', '=', 'student_enrollments.academic_class_arm_id')
            ->where('student_enrollments.school_id', $student->school_id)
            ->where('student_enrollments.student_id', $student->id)
            ->where('student_enrollments.academic_term_id', $snapshot->academic_term_id)
            ->whereIn('student_enrollments.status', ['active', 'ended', 'withdrawn'])
            ->orderByDesc('student_enrollments.id')
            ->select(['academic_levels.name as level_name', 'academic_sections.name as section_name', 'academic_class_arms.name as class_arm_name'])
            ->first();
        $entries = DB::table('academic_result_snapshot_entries as entries')
            ->join('academic_subject_offerings as offerings', 'offerings.id', '=', 'entries.academic_subject_offering_id')
            ->join('academic_subjects as subjects', 'subjects.id', '=', 'offerings.academic_subject_id')
            ->where('entries.school_id', $student->school_id)
            ->where('entries.academic_result_snapshot_id', $snapshot->id)
            ->where('entries.student_id', $student->id)
            ->where('entries.status', 'computed')
            ->whereNull('entries.error_code')
            ->orderBy('offerings.display_order')
            ->orderBy('entries.id')
            ->select(['entries.public_id', 'offerings.public_id as offering_public_id', 'subjects.name as subject_name', 'entries.total_score', 'entries.total_max_marks', 'entries.percentage', 'entries.grade', 'entries.grade_label', 'entries.is_passing', 'entries.remark'])
            ->get()
            ->map(fn (object $entry): array => [
                'id' => (string) $entry->public_id,
                'subject_offering_id' => (string) $entry->offering_public_id,
                'subject_name' => (string) $entry->subject_name,
                'total_score' => $entry->total_score === null ? null : (float) $entry->total_score,
                'total_max_marks' => $entry->total_max_marks === null ? null : (float) $entry->total_max_marks,
                'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
                'grade' => $entry->grade === null ? null : (string) $entry->grade,
                'grade_label' => $entry->grade_label === null ? null : (string) $entry->grade_label,
                'is_passing' => $entry->is_passing === null ? null : (bool) $entry->is_passing,
                'remark' => $entry->remark === null ? null : (string) $entry->remark,
            ])->values()->all();

        $hasSuccessor = DB::table('academic_result_snapshots')->where('supersedes_snapshot_id', $snapshot->id)->where('status', 'published')->exists();

        return [
            'id' => (string) $snapshot->public_id,
            'session' => ['id' => (string) $snapshot->session_public_id, 'name' => (string) $snapshot->session_name],
            'term' => ['id' => (string) $snapshot->term_public_id, 'name' => (string) $snapshot->term_name],
            'snapshot_version' => (int) $snapshot->snapshot_version,
            'is_current' => ! $hasSuccessor,
            'published_at' => (string) $snapshot->published_at,
            'placement' => $placement === null ? null : ['level' => $placement->level_name, 'section' => $placement->section_name, 'class_arm' => $placement->class_arm_name],
            'subjects' => $entries,
        ];
    }

    private function audit(UserIdentity $actor, int $count, ?string $school): void
    {
        $this->audit->execute(new AuditEventData(action: 'student.academic_history_read', subjectType: 'student_academic_history_projection', actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), metadata: ['caller_type' => 'student', 'result_count' => $count, 'school_scope' => $school]));
    }
}
