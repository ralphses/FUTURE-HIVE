<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class ParentDashboardAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor): LengthAwarePaginator
    {
        $paginator = $this->relationships($actor)->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $items = $paginator->getCollection()->map(fn (object $row): array => $this->child($row))->values();
        $paginator->setCollection($items);
        $this->auditRead($actor, (int) $paginator->total());

        return $paginator;
    }

    /** @return array{child: array<string, mixed>} */
    public function childView(UserIdentity $actor, string $student): array
    {
        $row = $this->relationships($actor)->where('students.public_id', $student)->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        $this->auditRead($actor, 1);

        return ['child' => $this->child($row)];
    }

    private function relationships(UserIdentity $actor): Builder
    {
        return DB::table('student_guardian_relationships')
            ->join('guardian_profiles', 'guardian_profiles.id', '=', 'student_guardian_relationships.guardian_profile_id')
            ->join('students', 'students.id', '=', 'student_guardian_relationships.student_id')
            ->join('schools', 'schools.id', '=', 'student_guardian_relationships.school_id')
            ->leftJoin('student_enrollments', function ($join): void {
                $join->on('student_enrollments.student_id', '=', 'students.id')
                    ->on('student_enrollments.school_id', '=', 'students.school_id')
                    ->where('student_enrollments.status', '=', 'active');
            })
            ->leftJoin('academic_levels', 'academic_levels.id', '=', 'student_enrollments.academic_level_id')
            ->leftJoin('academic_sections', 'academic_sections.id', '=', 'student_enrollments.academic_section_id')
            ->leftJoin('academic_class_arms', 'academic_class_arms.id', '=', 'student_enrollments.academic_class_arm_id')
            ->where('guardian_profiles.user_id', $actor->getKey())
            ->where('student_guardian_relationships.status', 'active')
            ->whereNotNull('student_guardian_relationships.verified_at')
            ->where('students.status', 'active')
            ->where('schools.status', 'active')
            ->select([
                'students.id as student_id',
                'students.public_id as student_public_id',
                'students.student_number',
                'students.display_name',
                'student_guardian_relationships.relationship_type',
                'student_guardian_relationships.school_id',
                'schools.public_id as school_public_id',
                'schools.name as school_name',
                'student_enrollments.id as enrollment_id',
                'student_enrollments.academic_term_id',
                'student_enrollments.academic_class_arm_id',
                'academic_levels.name as level_name',
                'academic_sections.name as section_name',
                'academic_class_arms.name as class_arm_name',
            ])
            ->orderBy('schools.name')
            ->orderBy('students.display_name')
            ->orderBy('students.id');
    }

    /** @return array<string, mixed> */
    private function child(object $row): array
    {
        $attendance = $row->enrollment_id !== null && DB::table('attendance_registers')
            ->where('school_id', $row->school_id)
            ->where('academic_class_arm_id', $row->academic_class_arm_id)
            ->whereIn('status', ['submitted', 'locked'])
            ->exists();
        $results = DB::table('academic_result_snapshot_entries')
            ->join('academic_result_snapshots', 'academic_result_snapshots.id', '=', 'academic_result_snapshot_entries.academic_result_snapshot_id')
            ->where('academic_result_snapshot_entries.school_id', $row->school_id)
            ->where('academic_result_snapshot_entries.student_id', $row->student_id)
            ->where('academic_result_snapshots.status', 'published')
            ->exists();
        $finance = DB::table('fee_invoices')->where('school_id', $row->school_id)->where('student_id', $row->student_id)->where('status', 'issued')->exists();
        $announcements = $row->academic_term_id !== null && $row->academic_class_arm_id !== null
            ? DB::table('teaching_announcements')->where('school_id', $row->school_id)->where('academic_term_id', $row->academic_term_id)->where('academic_class_arm_id', $row->academic_class_arm_id)->where('status', 'published')->count()
            : 0;

        return [
            'student_id' => (string) $row->student_public_id,
            'student_number' => (string) $row->student_number,
            'display_name' => (string) $row->display_name,
            'relationship_type' => (string) $row->relationship_type,
            'school' => ['id' => (string) $row->school_public_id, 'name' => (string) $row->school_name],
            'placement' => [
                'level' => $row->level_name === null ? null : (string) $row->level_name,
                'section' => $row->section_name === null ? null : (string) $row->section_name,
                'class_arm' => $row->class_arm_name === null ? null : (string) $row->class_arm_name,
            ],
            'availability' => [
                'published_results' => (bool) $results,
                'attendance_summary' => (bool) $attendance,
                'financial_statement' => (bool) $finance,
                'published_announcements' => (int) $announcements,
            ],
        ];
    }

    private function auditRead(UserIdentity $actor, int $resultCount): void
    {
        $this->audit->execute(new AuditEventData(
            action: 'parent.dashboard_read',
            subjectType: 'parent_dashboard_projection',
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['result_count' => $resultCount, 'caller_type' => 'verified_guardian'],
        ));
    }
}
