<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Contexts\Registry\Domain\Models\StudentEnrollmentChange;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentEnrollmentAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorize($actor, $school, 'students.enrollments.read');
        $studentRecord = $this->student($student);

        return ['items' => StudentEnrollment::query()
            ->where('student_id', $studentRecord->getKey())
            ->with(['session', 'term', 'level', 'section', 'classArm'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StudentEnrollment $enrollment): array => $this->data($enrollment))
            ->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $student, string $enrollment): array
    {
        $this->authorize($actor, $school, 'students.enrollments.read');
        $studentRecord = $this->student($student);

        return $this->data($this->find($enrollment, $studentRecord->getKey()));
    }

    /** @param array{session_id: string, term_id: string, level_id: string, section_id: string, class_arm_id: string, start_date: string} $data
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $student, array $data): array
    {
        $this->authorize($actor, $school, 'students.enrollments.manage');
        $context = TenantContext::require();

        return DB::transaction(function () use ($actor, $student, $data): array {
            $studentRecord = $this->student($student, true);
            if ($studentRecord->status !== 'active') {
                throw $this->invalid('Only active students can be enrolled.');
            }

            $session = AcademicSession::query()->where('public_id', $data['session_id'])->lockForUpdate()->first();
            $term = AcademicTerm::query()->where('public_id', $data['term_id'])->lockForUpdate()->first();
            $level = AcademicLevel::query()->where('public_id', $data['level_id'])->lockForUpdate()->first();
            $section = AcademicSection::query()->where('public_id', $data['section_id'])->lockForUpdate()->first();
            $classArm = AcademicClassArm::query()->where('public_id', $data['class_arm_id'])->lockForUpdate()->first();

            if (! $session instanceof AcademicSession || ! $term instanceof AcademicTerm || ! $level instanceof AcademicLevel || ! $section instanceof AcademicSection || ! $classArm instanceof AcademicClassArm) {
                throw new ModelNotFoundException;
            }
            if ((int) $term->academic_session_id !== (int) $session->getKey() || (int) $classArm->academic_level_id !== (int) $level->getKey() || (int) $classArm->academic_section_id !== (int) $section->getKey() || (int) $section->academic_level_id !== (int) $level->getKey()) {
                throw new ModelNotFoundException;
            }
            if ($session->status === 'closed' || $term->status === 'closed' || $level->status !== 'active' || $section->status !== 'active' || $classArm->status !== 'active') {
                throw $this->invalid('Enrollments require an active class structure and an open academic term.');
            }

            $startDate = Carbon::createFromFormat('Y-m-d', $data['start_date']);
            if ($startDate->lt($term->start_date) || $startDate->gt($term->end_date)) {
                throw $this->invalid('The enrollment date must fall within the academic term.');
            }
            if (StudentEnrollment::query()->where('student_id', $studentRecord->getKey())->where('academic_term_id', $term->getKey())->where('status', 'active')->exists()) {
                throw $this->invalid('The student already has an active enrollment for this term.');
            }
            $occupied = StudentEnrollment::query()->where('academic_class_arm_id', $classArm->getKey())->where('academic_term_id', $term->getKey())->where('status', 'active')->count();
            if ($occupied >= $classArm->capacity) {
                throw $this->invalid('The selected class arm has reached capacity.');
            }

            $enrollment = StudentEnrollment::query()->create([
                'student_id' => $studentRecord->getKey(),
                'academic_session_id' => $session->getKey(),
                'academic_term_id' => $term->getKey(),
                'academic_level_id' => $level->getKey(),
                'academic_section_id' => $section->getKey(),
                'academic_class_arm_id' => $classArm->getKey(),
                'start_date' => $startDate->toDateString(),
                'status' => 'active',
                'created_by' => $actor->getAuthIdentifier(),
            ]);
            $this->audit($actor, 'student.enrollment_created', (string) $enrollment->public_id, ['status' => 'active']);

            return $this->data($enrollment->load(['session', 'term', 'level', 'section', 'classArm']));
        });
    }

    /** @return array<string, mixed> */
    public function end(UserIdentity $actor, string $school, string $student, string $enrollment, string $reason): array
    {
        $this->authorize($actor, $school, 'students.enrollments.manage');
        $studentRecord = $this->student($student);

        return DB::transaction(function () use ($actor, $studentRecord, $enrollment, $reason): array {
            $record = $this->find($enrollment, $studentRecord->getKey(), true);
            if ($record->status === 'ended') {
                return $this->data($record);
            }
            $record->forceFill(['status' => 'ended', 'end_date' => now()->toDateString(), 'ended_by' => $actor->getAuthIdentifier(), 'end_reason' => trim($reason)])->save();
            $this->audit($actor, 'student.enrollment_ended', (string) $record->public_id, ['from' => 'active', 'to' => 'ended']);

            return $this->data($record);
        });
    }

    /** @param array{session_id: string, term_id: string, level_id: string, section_id: string, class_arm_id: string, effective_date: string, reason: string} $data
     * @return array<string, mixed>
     */
    public function transfer(UserIdentity $actor, string $school, string $student, string $enrollment, array $data): array
    {
        $this->authorize($actor, $school, 'students.enrollments.transfer');

        return DB::transaction(function () use ($actor, $student, $enrollment, $data): array {
            $studentRecord = $this->student($student, true);
            if ($studentRecord->status !== 'active') {
                throw $this->invalid('Only active students can be transferred.');
            }

            $current = $this->find($enrollment, $studentRecord->getKey(), true);
            if ($current->status === 'ended') {
                $replacement = StudentEnrollmentChange::query()
                    ->where('enrollment_id', $current->getKey())
                    ->where('change_type', 'transfer')
                    ->first()?->replacementEnrollment;
                if ($replacement instanceof StudentEnrollment) {
                    return $this->data($replacement->load(['session', 'term', 'level', 'section', 'classArm']));
                }
                throw $this->invalid('Only active enrollments can be transferred.');
            }

            $session = AcademicSession::query()->where('public_id', $data['session_id'])->lockForUpdate()->first();
            $term = AcademicTerm::query()->where('public_id', $data['term_id'])->lockForUpdate()->first();
            $level = AcademicLevel::query()->where('public_id', $data['level_id'])->lockForUpdate()->first();
            $section = AcademicSection::query()->where('public_id', $data['section_id'])->lockForUpdate()->first();
            $classArm = AcademicClassArm::query()->where('public_id', $data['class_arm_id'])->lockForUpdate()->first();

            if (! $session instanceof AcademicSession || ! $term instanceof AcademicTerm || ! $level instanceof AcademicLevel || ! $section instanceof AcademicSection || ! $classArm instanceof AcademicClassArm) {
                throw new ModelNotFoundException;
            }
            if ((int) $current->academic_term_id !== (int) $term->getKey() || (int) $current->academic_session_id !== (int) $session->getKey() || (int) $term->academic_session_id !== (int) $session->getKey() || (int) $classArm->academic_level_id !== (int) $level->getKey() || (int) $classArm->academic_section_id !== (int) $section->getKey() || (int) $section->academic_level_id !== (int) $level->getKey()) {
                throw new ModelNotFoundException;
            }
            if ((int) $current->academic_class_arm_id === (int) $classArm->getKey()) {
                throw $this->invalid('The replacement class arm must be different from the current class arm.');
            }
            if ($session->status === 'closed' || $term->status === 'closed' || $level->status !== 'active' || $section->status !== 'active' || $classArm->status !== 'active') {
                throw $this->invalid('Transfers require an active class structure and an open academic term.');
            }

            $effectiveDate = Carbon::createFromFormat('Y-m-d', $data['effective_date']);
            if ($effectiveDate->lt($term->start_date) || $effectiveDate->gt($term->end_date)) {
                throw $this->invalid('The transfer date must fall within the academic term.');
            }
            $occupied = StudentEnrollment::query()->where('academic_class_arm_id', $classArm->getKey())->where('academic_term_id', $term->getKey())->where('status', 'active')->count();
            if ($occupied >= $classArm->capacity) {
                throw $this->invalid('The replacement class arm has reached capacity.');
            }

            $current->forceFill(['status' => 'ended', 'end_date' => $effectiveDate->toDateString(), 'ended_by' => $actor->getAuthIdentifier(), 'end_reason' => trim($data['reason'])])->save();
            $replacement = StudentEnrollment::query()->create([
                'student_id' => $studentRecord->getKey(),
                'academic_session_id' => $session->getKey(),
                'academic_term_id' => $term->getKey(),
                'academic_level_id' => $level->getKey(),
                'academic_section_id' => $section->getKey(),
                'academic_class_arm_id' => $classArm->getKey(),
                'start_date' => $effectiveDate->toDateString(),
                'status' => 'active',
                'created_by' => $actor->getAuthIdentifier(),
            ]);
            StudentEnrollmentChange::query()->create([
                'student_id' => $studentRecord->getKey(),
                'enrollment_id' => $current->getKey(),
                'replacement_enrollment_id' => $replacement->getKey(),
                'change_type' => 'transfer',
                'effective_date' => $effectiveDate->toDateString(),
                'reason' => trim($data['reason']),
                'created_by' => $actor->getAuthIdentifier(),
            ]);
            $this->audit($actor, 'student.enrollment_transferred', (string) $replacement->public_id, ['from' => (string) $current->public_id, 'to' => (string) $replacement->public_id]);

            return $this->data($replacement->load(['session', 'term', 'level', 'section', 'classArm']));
        });
    }

    /** @return array<string, mixed> */
    public function withdraw(UserIdentity $actor, string $school, string $student, string $enrollment, string $reason): array
    {
        $this->authorize($actor, $school, 'students.enrollments.withdraw');
        $studentRecord = $this->student($student);

        return DB::transaction(function () use ($actor, $studentRecord, $enrollment, $reason): array {
            $record = $this->find($enrollment, $studentRecord->getKey(), true);
            if ($record->status === 'ended') {
                return $this->data($record);
            }
            $record->forceFill(['status' => 'ended', 'end_date' => now()->toDateString(), 'ended_by' => $actor->getAuthIdentifier(), 'end_reason' => trim($reason)])->save();
            StudentEnrollmentChange::query()->create([
                'student_id' => $studentRecord->getKey(),
                'enrollment_id' => $record->getKey(),
                'change_type' => 'withdrawal',
                'effective_date' => now()->toDateString(),
                'reason' => trim($reason),
                'created_by' => $actor->getAuthIdentifier(),
            ]);
            $this->audit($actor, 'student.enrollment_withdrawn', (string) $record->public_id, ['from' => 'active', 'to' => 'ended']);

            return $this->data($record);
        });
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function history(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorize($actor, $school, 'students.enrollments.read');
        $studentRecord = $this->student($student);

        return ['items' => StudentEnrollmentChange::query()
            ->where('student_id', $studentRecord->getKey())
            ->with(['enrollment', 'replacementEnrollment'])
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StudentEnrollmentChange $change): array => [
                'id' => (string) $change->public_id,
                'type' => (string) $change->change_type,
                'effective_date' => CarbonImmutable::parse((string) $change->effective_date)->toDateString(),
                'enrollment_id' => (string) $change->enrollment?->public_id,
                'replacement_enrollment_id' => $change->replacementEnrollment === null ? null : (string) $change->replacementEnrollment->public_id,
            ])
            ->all()];
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function student(string $publicId, bool $lock = false): Student
    {
        $query = Student::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $student = $query->first();
        if (! $student instanceof Student) {
            throw new ModelNotFoundException;
        }

        return $student;
    }

    private function find(string $publicId, int $studentId, bool $lock = false): StudentEnrollment
    {
        $query = StudentEnrollment::query()->where('public_id', $publicId)->where('student_id', $studentId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->first();
        if (! $record instanceof StudentEnrollment) {
            throw new ModelNotFoundException;
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function data(StudentEnrollment $record): array
    {
        return [
            'id' => (string) $record->public_id,
            'student_id' => (string) $record->student?->public_id,
            'session_id' => (string) $record->session?->public_id,
            'term_id' => (string) $record->term?->public_id,
            'level_id' => (string) $record->level?->public_id,
            'section_id' => (string) $record->section?->public_id,
            'class_arm_id' => (string) $record->classArm?->public_id,
            'start_date' => CarbonImmutable::parse((string) $record->start_date)->toDateString(),
            'end_date' => $record->end_date === null ? null : CarbonImmutable::parse((string) $record->end_date)->toDateString(),
            'status' => (string) $record->status,
        ];
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['enrollment' => $message]);
    }

    /** @param array<string, mixed> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'student_enrollment', subjectPublicId: $publicId, schoolId: TenantContext::require()->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'students.enrollments.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }
}
