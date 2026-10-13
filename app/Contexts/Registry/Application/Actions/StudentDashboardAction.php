<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class StudentDashboardAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return array{students: list<array<string, mixed>>} */
    public function handle(UserIdentity $actor): array
    {
        $students = DB::table('students')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->join('school_memberships', function ($join) use ($actor): void {
                $join->on('school_memberships.school_id', '=', 'students.school_id')
                    ->where('school_memberships.user_id', '=', $actor->getKey())
                    ->where('school_memberships.status', '=', 'active');
            })
            ->join('membership_roles', function ($join): void {
                $join->on('membership_roles.school_membership_id', '=', 'school_memberships.id')
                    ->whereNull('membership_roles.revoked_at');
            })
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'membership_roles.role_id')->where('roles.key', '=', 'student');
            })
            ->where('students.user_id', $actor->getKey())
            ->where('students.status', 'active')
            ->where('schools.status', 'active')
            ->select('students.*', 'schools.public_id as school_public_id', 'schools.name as school_name')
            ->orderBy('students.school_id')
            ->orderBy('students.display_name')
            ->orderBy('students.id')
            ->get();

        if ($students->isEmpty()) {
            throw new ModelNotFoundException;
        }

        $this->audit($actor, $students->count());

        return ['students' => $students->map(fn (object $student): array => $this->studentData($student))->values()->all()];
    }

    /** @return array<string, mixed> */
    private function studentData(object $student): array
    {
        $enrollment = DB::table('student_enrollments')
            ->leftJoin('academic_levels', 'academic_levels.id', '=', 'student_enrollments.academic_level_id')
            ->leftJoin('academic_sections', 'academic_sections.id', '=', 'student_enrollments.academic_section_id')
            ->leftJoin('academic_class_arms', 'academic_class_arms.id', '=', 'student_enrollments.academic_class_arm_id')
            ->where('student_enrollments.student_id', $student->id)
            ->where('student_enrollments.school_id', $student->school_id)
            ->where('student_enrollments.status', 'active')
            ->orderByDesc('student_enrollments.id')
            ->select(['student_enrollments.academic_class_arm_id', 'academic_levels.name as level_name', 'academic_sections.name as section_name', 'academic_class_arms.name as class_arm_name'])
            ->first();
        $resultQuery = DB::table('academic_result_snapshot_entries')
            ->join('academic_result_snapshots', 'academic_result_snapshots.id', '=', 'academic_result_snapshot_entries.academic_result_snapshot_id')
            ->join('academic_result_gates', 'academic_result_gates.id', '=', 'academic_result_snapshots.academic_result_gate_id')
            ->join('academic_subject_offerings', 'academic_subject_offerings.id', '=', 'academic_result_snapshot_entries.academic_subject_offering_id')
            ->join('academic_subjects', 'academic_subjects.id', '=', 'academic_subject_offerings.academic_subject_id')
            ->where('academic_result_snapshot_entries.school_id', $student->school_id)
            ->where('academic_result_snapshot_entries.student_id', $student->id)
            ->whereNull('academic_result_snapshot_entries.error_code')
            ->where('academic_result_snapshots.status', 'published')
            ->where('academic_result_gates.status', 'published')
            ->select(['academic_subject_offerings.public_id as offering_public_id', 'academic_subjects.name as subject_name', 'academic_result_snapshot_entries.percentage', 'academic_result_snapshot_entries.grade', 'academic_result_snapshot_entries.is_passing', 'academic_result_snapshot_entries.remark']);
        $attendance = $enrollment !== null && DB::table('attendance_registers')->where('school_id', $student->school_id)->where('academic_class_arm_id', $enrollment->academic_class_arm_id)->whereIn('status', ['submitted', 'locked'])->exists();

        return [
            'student_id' => (string) $student->public_id,
            'student_number' => (string) $student->student_number,
            'display_name' => (string) $student->display_name,
            'school' => ['id' => (string) $student->school_public_id, 'name' => (string) $student->school_name],
            'placement' => $enrollment === null ? null : [
                'level' => $enrollment->level_name === null ? null : (string) $enrollment->level_name,
                'section' => $enrollment->section_name === null ? null : (string) $enrollment->section_name,
                'class_arm' => $enrollment->class_arm_name === null ? null : (string) $enrollment->class_arm_name,
            ],
            'availability' => [
                'attendance_summary' => (bool) $attendance,
                'published_results' => $resultQuery->exists(),
            ],
            'published_result_summary' => $resultQuery->orderBy('academic_subject_offerings.display_order')->orderBy('academic_result_snapshot_entries.id')->get()->map(fn (object $entry): array => [
                'subject_offering_id' => (string) $entry->offering_public_id,
                'subject_name' => (string) $entry->subject_name,
                'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
                'grade' => $entry->grade === null ? null : (string) $entry->grade,
                'is_passing' => $entry->is_passing === null ? null : (bool) $entry->is_passing,
                'remark' => $entry->remark === null ? null : (string) $entry->remark,
            ])->values()->all(),
        ];
    }

    private function audit(UserIdentity $actor, int $count): void
    {
        $this->audit->execute(new AuditEventData(
            action: 'student.dashboard_read',
            subjectType: 'student_dashboard_projection',
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['result_count' => $count, 'caller_type' => 'student'],
        ));
    }
}
