<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAttendanceCorrection;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceEntry;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceRegister;
use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\ClassTeacherAssignment;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicAttendanceAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date): array
    {
        [$academicTerm, $academicClassArm, $attendanceDate] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $date, 'attendance.read');
        $this->authorizeActorForClassArm($actor, $academicTerm, $academicClassArm, $attendanceDate);

        $register = AcademicAttendanceRegister::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('academic_class_arm_id', $academicClassArm->getKey())
            ->whereDate('attendance_date', $attendanceDate->toDateString())
            ->with('entries.student')
            ->first();

        return $this->response($register, $academicTerm, $academicClassArm, $attendanceDate);
    }

    /** @param array{entries: list<array{student_id: string, status: string, reason?: string|null}>} $input
     * @return array<string, mixed>
     */
    public function replace(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date, array $input): array
    {
        [$academicTerm, $academicClassArm, $attendanceDate] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $date, 'attendance.manage');
        $this->authorizeActorForClassArm($actor, $academicTerm, $academicClassArm, $attendanceDate);

        return DB::transaction(function () use ($actor, $academicTerm, $academicClassArm, $attendanceDate, $input): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $academicClassArm = AcademicClassArm::query()->whereKey($academicClassArm->getKey())->lockForUpdate()->firstOrFail();
            $roster = $this->roster($academicTerm, $academicClassArm, $attendanceDate);
            $students = $roster->keyBy(fn (StudentEnrollment $enrollment): string => (string) $enrollment->student->public_id);
            $register = AcademicAttendanceRegister::query()
                ->where('academic_term_id', $academicTerm->getKey())
                ->where('academic_class_arm_id', $academicClassArm->getKey())
                ->whereDate('attendance_date', $attendanceDate->toDateString())
                ->lockForUpdate()
                ->first();

            if ($register instanceof AcademicAttendanceRegister && $register->status !== 'draft') {
                throw new ModelNotFoundException;
            }

            if (! $register instanceof AcademicAttendanceRegister) {
                $register = AcademicAttendanceRegister::query()->create([
                    'school_id' => $academicClassArm->school_id,
                    'academic_session_id' => $academicTerm->academic_session_id,
                    'academic_term_id' => $academicTerm->getKey(),
                    'academic_class_arm_id' => $academicClassArm->getKey(),
                    'attendance_date' => $attendanceDate->toDateString(),
                    'status' => 'draft',
                    'created_by' => $actor->getKey(),
                ]);
            }

            foreach ($input['entries'] as $entry) {
                $studentId = (string) $entry['student_id'];
                if (! $students->has($studentId)) {
                    throw new ModelNotFoundException;
                }

                $status = (string) $entry['status'];
                if ($status === 'excused' && trim((string) ($entry['reason'] ?? '')) === '') {
                    throw ValidationException::withMessages(['entries' => 'An excused attendance entry requires a reason.']);
                }

                $attendanceEntry = AcademicAttendanceEntry::query()->firstOrNew([
                    'attendance_register_id' => $register->getKey(),
                    'student_id' => $students[$studentId]->student_id,
                ]);
                $attendanceEntry->fill([
                    'school_id' => $academicClassArm->school_id,
                    'status' => $status,
                    'reason' => $status === 'excused' ? trim((string) ($entry['reason'] ?? '')) : null,
                    'recorded_by' => $actor->getKey(),
                    'recorded_at' => now(),
                ]);
                $attendanceEntry->save();
            }

            foreach ($roster as $enrollment) {
                AcademicAttendanceEntry::query()->firstOrCreate([
                    'attendance_register_id' => $register->getKey(),
                    'student_id' => $enrollment->student_id,
                ], [
                    'school_id' => $academicClassArm->school_id,
                    'status' => 'unrecorded',
                ]);
            }

            $this->audit->execute(new AuditEventData(
                action: 'academic.attendance.updated',
                subjectType: 'attendance_register',
                subjectPublicId: (string) $register->public_id,
                schoolId: (int) $academicClassArm->school_id,
                actorId: $actor->getKey(),
                stateTransition: ['from' => 'draft', 'to' => 'draft'],
                metadata: ['attendance_date' => $attendanceDate->toDateString(), 'entry_count' => count($input['entries'])],
                requiresSchoolContext: true,
            ));

            return $this->response($register->fresh('entries.student'), $academicTerm, $academicClassArm, $attendanceDate);
        });
    }

    /** @return array<string, mixed> */
    public function submit(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date): array
    {
        [$academicTerm, $academicClassArm, $attendanceDate] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $date, 'attendance.manage');
        $this->authorizeActorForClassArm($actor, $academicTerm, $academicClassArm, $attendanceDate);

        return DB::transaction(function () use ($actor, $academicTerm, $academicClassArm, $attendanceDate): array {
            $register = $this->lockedRegister($academicTerm, $academicClassArm, $attendanceDate);
            if (! $register instanceof AcademicAttendanceRegister) {
                throw new ModelNotFoundException;
            }

            if ($register->status === 'draft') {
                $this->assertCompleteRoster($register, $academicTerm, $academicClassArm, $attendanceDate);
                $register->update([
                    'status' => 'submitted',
                    'submitted_by' => $actor->getKey(),
                    'submitted_at' => now(),
                    'submitted_request_id' => Context::get('request_id'),
                    'updated_by' => $actor->getKey(),
                ]);
                $this->auditTransition($actor, $register, $academicClassArm, 'draft', 'submitted', 'academic.attendance.submitted');
            }

            return $this->response($register->fresh('entries.student'), $academicTerm, $academicClassArm, $attendanceDate);
        });
    }

    /** @return array<string, mixed> */
    public function lock(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date): array
    {
        [$academicTerm, $academicClassArm, $attendanceDate] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $date, 'attendance.manage');
        $this->authorizeLeadership($actor);

        return DB::transaction(function () use ($actor, $academicTerm, $academicClassArm, $attendanceDate): array {
            $register = $this->lockedRegister($academicTerm, $academicClassArm, $attendanceDate);
            if (! $register instanceof AcademicAttendanceRegister || $register->status === 'draft') {
                throw new ModelNotFoundException;
            }

            if ($register->status === 'submitted') {
                $register->update([
                    'status' => 'locked',
                    'locked_by' => $actor->getKey(),
                    'locked_at' => now(),
                    'locked_request_id' => Context::get('request_id'),
                    'updated_by' => $actor->getKey(),
                ]);
                $this->auditTransition($actor, $register, $academicClassArm, 'submitted', 'locked', 'academic.attendance.locked');
            }

            return $this->response($register->fresh('entries.student'), $academicTerm, $academicClassArm, $attendanceDate);
        });
    }

    /** @return array<string, mixed> */
    public function submission(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date): array
    {
        [$academicTerm, $academicClassArm, $attendanceDate] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $date, 'attendance.read');
        $this->authorizeActorForClassArm($actor, $academicTerm, $academicClassArm, $attendanceDate);

        $register = $this->lockedRegister($academicTerm, $academicClassArm, $attendanceDate);

        return [
            'register' => $register === null ? null : [
                'id' => (string) $register->public_id,
                'status' => (string) $register->status,
                'date' => $attendanceDate->toDateString(),
                'submitted_at' => $register->submitted_at === null ? null : CarbonImmutable::parse($register->submitted_at)->toIso8601String(),
                'locked_at' => $register->locked_at === null ? null : CarbonImmutable::parse($register->locked_at)->toIso8601String(),
            ],
            'submitted' => $register?->status === 'submitted' || $register?->status === 'locked',
            'locked' => $register?->status === 'locked',
        ];
    }

    /** @return array<string, mixed> */
    public function corrections(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date): array
    {
        [$academicTerm, $academicClassArm, $attendanceDate] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $date, 'attendance.read');
        $this->authorizeActorForClassArm($actor, $academicTerm, $academicClassArm, $attendanceDate);
        $register = $this->lockedRegister($academicTerm, $academicClassArm, $attendanceDate);

        if (! $register instanceof AcademicAttendanceRegister) {
            throw new ModelNotFoundException;
        }

        $corrections = AcademicAttendanceCorrection::query()
            ->where('attendance_register_id', $register->getKey())
            ->with(['entry', 'corrector'])
            ->orderBy('corrected_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AcademicAttendanceCorrection $correction): array => $this->correctionResponse($correction))
            ->values()
            ->all();

        return ['register' => ['id' => (string) $register->public_id, 'status' => (string) $register->status, 'date' => $attendanceDate->toDateString()], 'corrections' => $corrections];
    }

    /** @param array{from: string, to: string} $input
     * @return array<string, mixed>
     */
    public function report(UserIdentity $actor, string $school, string $session, string $term, string $classArm, array $input): array
    {
        [$academicTerm, $academicClassArm, $from] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $input['from'], 'attendance.reports.read');
        $to = $this->parseReportDate($input['to']);
        if ($to->lt($from) || $to->gt(CarbonImmutable::parse((string) $academicTerm->end_date)) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'The attendance report range must stay within the active term and be no longer than 366 days.']);
        }

        $this->authorizeActorForClassArm($actor, $academicTerm, $academicClassArm, $from);
        $registers = AcademicAttendanceRegister::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('academic_class_arm_id', $academicClassArm->getKey())
            ->whereIn('status', ['submitted', 'locked'])
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->with('entries')
            ->orderBy('attendance_date')
            ->orderBy('id')
            ->get();

        $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'unrecorded' => 0];
        $days = [];
        foreach ($registers as $register) {
            foreach ($register->entries as $entry) {
                $status = (string) $entry->status;
                if (array_key_exists($status, $counts)) {
                    $counts[$status]++;
                }
            }
            $days[] = [
                'date' => CarbonImmutable::parse($register->attendance_date)->toDateString(),
                'status' => (string) $register->status,
                'entry_count' => $register->entries->count(),
                'complete' => true,
            ];
        }

        $includedDays = count($days);
        $totalDays = $from->diffInDays($to) + 1;
        $recordedDenominator = $counts['present'] + $counts['absent'] + $counts['late'] + $counts['excused'];
        $attendedCount = $counts['present'] + $counts['late'];

        $this->audit->execute(new AuditEventData(
            action: 'academic.attendance.report.viewed',
            subjectType: 'attendance_class_arm',
            subjectPublicId: (string) $academicClassArm->public_id,
            schoolId: (int) $academicClassArm->school_id,
            actorId: $actor->getKey(),
            requestId: Context::get('request_id'),
            metadata: ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'included_days' => $includedDays],
            requiresSchoolContext: true,
        ));

        return [
            'school' => ['id' => $school],
            'session' => ['id' => (string) $academicTerm->session->public_id, 'name' => (string) $academicTerm->session->name],
            'term' => ['id' => (string) $academicTerm->public_id, 'name' => (string) $academicTerm->name],
            'class_arm' => ['id' => (string) $academicClassArm->public_id, 'name' => (string) $academicClassArm->name],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'submitted_registers' => $registers->where('status', 'submitted')->count(),
                'locked_registers' => $registers->where('status', 'locked')->count(),
                'included_register_days' => $includedDays,
                'missing_register_days' => $totalDays - $includedDays,
                'counts' => $counts,
                'recorded_denominator' => $recordedDenominator,
                'attended_count' => $attendedCount,
                'attendance_rate' => $recordedDenominator === 0 ? null : round(($attendedCount / $recordedDenominator) * 100, 2),
            ],
            'days' => $days,
        ];
    }

    /** @param array{entry_id: string, status: string, reason?: string|null, correction_reason: string} $input
     * @return array<string, mixed>
     */
    public function correct(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date, array $input): array
    {
        [$academicTerm, $academicClassArm, $attendanceDate] = $this->authorizeScope($actor, $school, $session, $term, $classArm, $date, 'attendance.manage');
        $this->authorizeActorForClassArm($actor, $academicTerm, $academicClassArm, $attendanceDate);

        return DB::transaction(function () use ($actor, $academicTerm, $academicClassArm, $attendanceDate, $input): array {
            $register = $this->lockedRegister($academicTerm, $academicClassArm, $attendanceDate);
            if (! $register instanceof AcademicAttendanceRegister) {
                throw new ModelNotFoundException;
            }

            $entry = AcademicAttendanceEntry::query()
                ->where('public_id', $input['entry_id'])
                ->where('attendance_register_id', $register->getKey())
                ->lockForUpdate()
                ->first();
            if (! $entry instanceof AcademicAttendanceEntry) {
                throw new ModelNotFoundException;
            }

            $requestId = Context::get('request_id');
            if (is_string($requestId)) {
                $existing = AcademicAttendanceCorrection::query()
                    ->where('attendance_entry_id', $entry->getKey())
                    ->where('request_id', $requestId)
                    ->with(['entry', 'corrector'])
                    ->first();
                if ($existing instanceof AcademicAttendanceCorrection) {
                    return ['entry' => ['id' => (string) $entry->public_id, 'status' => (string) $entry->status, 'reason' => $entry->reason], 'correction' => $this->correctionResponse($existing)];
                }
            }

            if ($register->status !== 'submitted') {
                throw new ModelNotFoundException;
            }

            $replacementReason = (string) ($input['reason'] ?? '');
            if ($input['status'] === 'excused' && trim($replacementReason) === '') {
                throw ValidationException::withMessages(['reason' => 'An excused attendance entry requires a reason.']);
            }
            $replacementReason = $input['status'] === 'excused' ? trim($replacementReason) : null;
            $correctionReason = trim($input['correction_reason']);
            if ($correctionReason === '') {
                throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required.']);
            }

            $sameCorrection = AcademicAttendanceCorrection::query()
                ->where('attendance_entry_id', $entry->getKey())
                ->where('replacement_status', $input['status'])
                ->where('correction_reason', $correctionReason)
                ->where(function ($query) use ($replacementReason): void {
                    if ($replacementReason === null) {
                        $query->whereNull('replacement_reason');
                    } else {
                        $query->where('replacement_reason', $replacementReason);
                    }
                })
                ->with(['entry', 'corrector'])
                ->latest('id')
                ->first();
            if ($sameCorrection instanceof AcademicAttendanceCorrection) {
                return ['entry' => ['id' => (string) $entry->public_id, 'status' => (string) $entry->status, 'reason' => $entry->reason], 'correction' => $this->correctionResponse($sameCorrection)];
            }

            $correction = AcademicAttendanceCorrection::query()->create([
                'school_id' => $academicClassArm->school_id,
                'attendance_register_id' => $register->getKey(),
                'attendance_entry_id' => $entry->getKey(),
                'previous_status' => (string) $entry->status,
                'previous_reason' => $entry->reason,
                'replacement_status' => (string) $input['status'],
                'replacement_reason' => $replacementReason,
                'correction_reason' => $correctionReason,
                'corrected_by' => $actor->getKey(),
                'request_id' => $requestId,
                'corrected_at' => now(),
            ]);
            $entry->update([
                'status' => $input['status'],
                'reason' => $replacementReason,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'academic.attendance.corrected',
                subjectType: 'attendance_register',
                subjectPublicId: (string) $register->public_id,
                schoolId: (int) $academicClassArm->school_id,
                actorId: $actor->getKey(),
                requestId: Context::get('request_id'),
                stateTransition: ['from' => (string) $correction->previous_status, 'to' => (string) $correction->replacement_status],
                metadata: ['attendance_date' => $attendanceDate->toDateString(), 'correction_id' => (string) $correction->public_id],
                requiresSchoolContext: true,
            ));

            return ['entry' => ['id' => (string) $entry->public_id, 'status' => (string) $entry->status, 'reason' => $entry->reason], 'correction' => $this->correctionResponse($correction->fresh(['entry', 'corrector']))];
        });
    }

    /** @return array{0: AcademicTerm, 1: AcademicClassArm, 2: CarbonImmutable} */
    private function authorizeScope(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $date, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }

        try {
            $attendanceDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()
            ->where('public_id', $term)
            ->whereHas('session', fn ($query): mixed => $query->where('public_id', $session)->where('school_id', $context->schoolId)->where('status', 'active'))
            ->with('session')
            ->first();
        $academicClassArm = AcademicClassArm::query()
            ->where('public_id', $classArm)
            ->where('school_id', $context->schoolId)
            ->where('status', 'active')
            ->whereHas('level', fn ($query): mixed => $query->where('status', 'active'))
            ->whereHas('section', fn ($query): mixed => $query->where('status', 'active'))
            ->first();

        if (! $academicTerm instanceof AcademicTerm || ! $academicClassArm instanceof AcademicClassArm || $academicTerm->status !== 'active' || $academicTerm->session?->status !== 'active' || $attendanceDate->lt(CarbonImmutable::parse((string) $academicTerm->start_date)) || $attendanceDate->gt(CarbonImmutable::parse((string) $academicTerm->end_date))) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $academicClassArm, $attendanceDate];
    }

    private function parseReportDate(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['to' => 'The attendance report end date must be a valid date.']);
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['to' => 'The attendance report end date must be a valid date.']);
        }

        return $parsed;
    }

    private function authorizeActorForClassArm(UserIdentity $actor, AcademicTerm $term, AcademicClassArm $classArm, CarbonImmutable $date): void
    {
        if ($this->isLeadership($actor)) {
            return;
        }

        $context = TenantContext::require();
        $assigned = ClassTeacherAssignment::query()
            ->where('school_id', $context->schoolId)
            ->where('academic_term_id', $term->getKey())
            ->where('academic_class_arm_id', $classArm->getKey())
            ->where('school_membership_id', $context->membershipId)
            ->where('status', 'active')
            ->whereDate('effective_start', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_end')->orWhereDate('effective_end', '>=', $date->toDateString());
            })
            ->whereHas('membership', fn ($query): mixed => $query->where('user_id', $actor->getKey())->where('status', 'active')->whereHas('roleAssignments', fn ($roles): mixed => $roles->whereNull('revoked_at')->whereHas('role', fn ($role): mixed => $role->where('key', 'teacher'))))
            ->whereHas('staffProfile', fn ($query): mixed => $query->where('employment_status', 'active'))
            ->exists();
        if (! $assigned) {
            throw new ModelNotFoundException;
        }
    }

    private function authorizeLeadership(UserIdentity $actor): void
    {
        if (! $this->isLeadership($actor)) {
            throw new ModelNotFoundException;
        }
    }

    private function isLeadership(UserIdentity $actor): bool
    {
        $context = TenantContext::require();

        return SchoolMembership::query()
            ->whereKey($context->membershipId)
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereHas('roleAssignments', fn ($query): mixed => $query->whereNull('revoked_at')->whereHas('role', fn ($role): mixed => $role->whereIn('key', ['school_admin', 'principal', 'proprietor'])))
            ->exists();
    }

    private function lockedRegister(AcademicTerm $term, AcademicClassArm $classArm, CarbonImmutable $date): ?AcademicAttendanceRegister
    {
        return AcademicAttendanceRegister::query()
            ->where('academic_term_id', $term->getKey())
            ->where('academic_class_arm_id', $classArm->getKey())
            ->whereDate('attendance_date', $date->toDateString())
            ->lockForUpdate()
            ->first();
    }

    private function assertCompleteRoster(AcademicAttendanceRegister $register, AcademicTerm $term, AcademicClassArm $classArm, CarbonImmutable $date): void
    {
        $rosterStudentIds = $this->roster($term, $classArm, $date)->pluck('student_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $entryStudentIds = $register->entries()->pluck('student_id')->map(static fn (mixed $id): int => (int) $id)->all();
        if (array_diff($rosterStudentIds, $entryStudentIds) !== [] || array_diff($entryStudentIds, $rosterStudentIds) !== []) {
            throw ValidationException::withMessages(['attendance' => 'All currently enrolled students must have an attendance entry before submission.']);
        }
    }

    private function auditTransition(UserIdentity $actor, AcademicAttendanceRegister $register, AcademicClassArm $classArm, string $from, string $to, string $action): void
    {
        $this->audit->execute(new AuditEventData(
            action: $action,
            subjectType: 'attendance_register',
            subjectPublicId: (string) $register->public_id,
            schoolId: (int) $classArm->school_id,
            actorId: $actor->getKey(),
            requestId: Context::get('request_id'),
            stateTransition: ['from' => $from, 'to' => $to],
            metadata: ['attendance_date' => (string) $register->attendance_date],
            requiresSchoolContext: true,
        ));
    }

    /** @return array<string, mixed> */
    private function correctionResponse(AcademicAttendanceCorrection $correction): array
    {
        return [
            'id' => (string) $correction->public_id,
            'entry_id' => (string) $correction->entry->public_id,
            'previous' => ['status' => (string) $correction->previous_status, 'reason' => $correction->previous_reason],
            'replacement' => ['status' => (string) $correction->replacement_status, 'reason' => $correction->replacement_reason],
            'correction_reason' => (string) $correction->correction_reason,
            'corrected_by' => (string) $correction->corrector->public_id,
            'corrected_at' => CarbonImmutable::parse($correction->corrected_at)->toIso8601String(),
        ];
    }

    /** @return Collection<int, StudentEnrollment> */
    private function roster(AcademicTerm $term, AcademicClassArm $classArm, CarbonImmutable $date): Collection
    {
        return StudentEnrollment::query()
            ->where('academic_term_id', $term->getKey())
            ->where('academic_class_arm_id', $classArm->getKey())
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString());
            })
            ->whereHas('student', fn ($query): mixed => $query->where('status', 'active'))
            ->with('student')
            ->orderBy('student_id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function response(?AcademicAttendanceRegister $register, AcademicTerm $term, AcademicClassArm $classArm, CarbonImmutable $date): array
    {
        $roster = $this->roster($term, $classArm, $date)->keyBy('student_id');
        $existing = $register?->entries?->keyBy('student_id') ?? collect();
        $entries = $roster->map(function (StudentEnrollment $enrollment) use ($existing): array {
            $entry = $existing->get($enrollment->student_id);

            return [
                'student' => ['id' => (string) $enrollment->student->public_id, 'student_number' => (string) $enrollment->student->student_number, 'display_name' => (string) $enrollment->student->display_name],
                'status' => $entry instanceof AcademicAttendanceEntry ? (string) $entry->status : 'unrecorded',
                'reason' => $entry instanceof AcademicAttendanceEntry ? $entry->reason : null,
            ];
        })->values()->all();

        return [
            'register' => ['id' => $register === null ? null : (string) $register->public_id, 'date' => $date->toDateString(), 'status' => $register === null ? 'draft' : (string) $register->status],
            'class_arm' => ['id' => (string) $classArm->public_id, 'name' => (string) $classArm->name],
            'term' => ['id' => (string) $term->public_id, 'name' => (string) $term->name],
            'entries' => $entries,
        ];
    }
}
