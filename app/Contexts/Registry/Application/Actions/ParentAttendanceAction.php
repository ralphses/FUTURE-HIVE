<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ParentAttendanceAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @param array{session: string, term: string, from: string, to: string} $input
     * @return LengthAwarePaginator<int, non-empty-array<string, mixed>>
     */
    public function index(UserIdentity $actor, array $input): LengthAwarePaginator
    {
        [$from, $to] = $this->period($input);
        $query = $this->relationships($actor)->select('students.*', 'schools.public_id as school_public_id', 'schools.name as school_name');
        $paginator = $query->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $items = $paginator->getCollection()->map(fn (object $row): ?array => $this->summary($row, $input, $from, $to))->filter()->values();
        $paginator->setCollection($items);
        $this->auditRead($actor, $items->count());

        return $paginator;
    }

    /** @param array{session: string, term: string, from: string, to: string} $input
     * @return array{attendance: array<string, mixed>}
     */
    public function child(UserIdentity $actor, string $student, array $input): array
    {
        [$from, $to] = $this->period($input);
        $row = $this->relationships($actor)->where('students.public_id', $student)->select('students.*', 'schools.public_id as school_public_id', 'schools.name as school_name')->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        $summary = $this->summary($row, $input, $from, $to);
        if ($summary === null) {
            throw new ModelNotFoundException;
        }

        $this->auditRead($actor, 1);

        return ['attendance' => $summary];
    }

    /** @param array{session: string, term: string, from: string, to: string} $input
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $input): array
    {
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $input['from']);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $input['to']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['to' => 'The attendance period must contain valid dates.']);
        }

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $from->format('Y-m-d') !== $input['from'] || $to->format('Y-m-d') !== $input['to'] || $to->lt($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'The attendance period must be no longer than 366 days.']);
        }

        return [$from, $to];
    }

    private function relationships(UserIdentity $actor): Builder
    {
        return DB::table('student_guardian_relationships')
            ->join('guardian_profiles', 'guardian_profiles.id', '=', 'student_guardian_relationships.guardian_profile_id')
            ->join('students', 'students.id', '=', 'student_guardian_relationships.student_id')
            ->join('schools', 'schools.id', '=', 'student_guardian_relationships.school_id')
            ->where('guardian_profiles.user_id', $actor->getKey())
            ->where('student_guardian_relationships.status', 'active')
            ->whereNotNull('student_guardian_relationships.verified_at')
            ->where('students.status', 'active')
            ->where('schools.status', 'active')
            ->orderBy('schools.name')
            ->orderBy('students.display_name')
            ->orderBy('students.id');
    }

    /** @return array<string, mixed>|null */
    /** @param array{session: string, term: string, from: string, to: string} $input
     * @return array<string, mixed>|null
     */
    private function summary(object $row, array $input, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $term = DB::table('academic_terms')
            ->join('academic_sessions', 'academic_sessions.id', '=', 'academic_terms.academic_session_id')
            ->where('academic_terms.public_id', $input['term'])
            ->where('academic_sessions.public_id', $input['session'])
            ->where('academic_terms.school_id', $row->school_id)
            ->where('academic_sessions.school_id', $row->school_id)
            ->where('academic_terms.status', 'active')
            ->where('academic_sessions.status', 'active')
            ->select([
                'academic_terms.id as term_id',
                'academic_terms.public_id as term_public_id',
                'academic_terms.name as term_name',
                'academic_terms.start_date',
                'academic_terms.end_date',
                'academic_sessions.public_id as session_public_id',
                'academic_sessions.name as session_name',
            ])
            ->first();
        if ($term === null || $from->lt(CarbonImmutable::parse((string) $term->start_date)) || $to->gt(CarbonImmutable::parse((string) $term->end_date))) {
            return null;
        }

        $enrollment = DB::table('student_enrollments')
            ->join('academic_levels', 'academic_levels.id', '=', 'student_enrollments.academic_level_id')
            ->join('academic_sections', 'academic_sections.id', '=', 'student_enrollments.academic_section_id')
            ->join('academic_class_arms', 'academic_class_arms.id', '=', 'student_enrollments.academic_class_arm_id')
            ->where('student_enrollments.school_id', $row->school_id)
            ->where('student_enrollments.student_id', $row->id)
            ->where('student_enrollments.academic_term_id', $term->term_id)
            ->where('student_enrollments.status', 'active')
            ->select([
                'student_enrollments.academic_class_arm_id',
                'academic_levels.name as level_name',
                'academic_sections.name as section_name',
                'academic_class_arms.public_id as class_arm_public_id',
                'academic_class_arms.name as class_arm_name',
            ])
            ->first();
        if ($enrollment === null) {
            return null;
        }

        $registers = DB::table('attendance_registers')
            ->where('school_id', $row->school_id)
            ->where('academic_term_id', $term->term_id)
            ->where('academic_class_arm_id', $enrollment->academic_class_arm_id)
            ->whereIn('status', ['submitted', 'locked'])
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('attendance_date')
            ->orderBy('id')
            ->get();
        $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'unrecorded' => 0];
        $days = [];
        foreach ($registers as $register) {
            $entry = DB::table('attendance_entries')
                ->where('school_id', $row->school_id)
                ->where('attendance_register_id', $register->id)
                ->where('student_id', $row->id)
                ->first(['id', 'status']);
            $status = $entry === null ? 'unrecorded' : (string) $entry->status;
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
            $correctionsAvailable = $entry !== null && DB::table('attendance_corrections')
                ->where('school_id', $row->school_id)
                ->where('attendance_register_id', $register->id)
                ->where('attendance_entry_id', $entry->id)
                ->exists();
            $days[] = [
                'date' => CarbonImmutable::parse((string) $register->attendance_date)->toDateString(),
                'status' => (string) $register->status,
                'attendance' => $status,
                'corrections_available' => (bool) $correctionsAvailable,
            ];
        }

        $totalDays = $from->diffInDays($to) + 1;
        $recordedDenominator = $counts['present'] + $counts['absent'] + $counts['late'] + $counts['excused'];
        $attendedCount = $counts['present'] + $counts['late'];

        return [
            'student' => ['id' => (string) $row->public_id, 'student_number' => (string) $row->student_number, 'display_name' => (string) $row->display_name],
            'school' => ['id' => (string) $row->school_public_id, 'name' => (string) $row->school_name],
            'session' => ['id' => (string) $term->session_public_id, 'name' => (string) $term->session_name],
            'term' => ['id' => (string) $term->term_public_id, 'name' => (string) $term->term_name],
            'class_arm' => ['id' => (string) $enrollment->class_arm_public_id, 'name' => (string) $enrollment->class_arm_name],
            'placement' => ['level' => (string) $enrollment->level_name, 'section' => (string) $enrollment->section_name],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'submitted_registers' => $registers->where('status', 'submitted')->count(),
                'locked_registers' => $registers->where('status', 'locked')->count(),
                'included_register_days' => count($days),
                'missing_register_days' => $totalDays - count($days),
                'counts' => $counts,
                'recorded_denominator' => $recordedDenominator,
                'attended_count' => $attendedCount,
                'attendance_rate' => $recordedDenominator === 0 ? null : round(($attendedCount / $recordedDenominator) * 100, 2),
            ],
            'days' => $days,
        ];
    }

    private function auditRead(UserIdentity $actor, int $count): void
    {
        $this->audit->execute(new AuditEventData(
            action: 'parent.attendance.viewed',
            subjectType: 'parent_attendance_projection',
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['result_count' => $count, 'caller_type' => 'verified_guardian'],
        ));
    }
}
