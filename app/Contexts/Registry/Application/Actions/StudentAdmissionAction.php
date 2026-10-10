<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentAdmissionAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'students.read');

        return ['items' => Student::query()->orderBy('display_name')->get()->map(fn (Student $student): array => $this->data($student))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorize($actor, $school, 'students.read');

        return $this->data($this->find($student));
    }

    /** @param array{student_number: string, display_name: string, admission_date?: string|null, metadata?: array<string, mixed>|null} $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'students.admit');

        return DB::transaction(function () use ($actor, $input): array {
            $number = trim($input['student_number']);
            if (Student::query()->where('student_number', $number)->exists()) {
                throw ValidationException::withMessages(['student_number' => ['The student number is already in use in this school.']]);
            }
            $student = Student::query()->create([
                'student_number' => $number,
                'display_name' => trim($input['display_name']),
                'admission_date' => $input['admission_date'] ?? now()->toDateString(),
                'status' => 'pending',
                'metadata' => $input['metadata'] ?? null,
            ]);
            $this->record($actor, 'student.admitted', (string) $student->public_id, ['status' => 'pending']);

            return $this->data($student);
        });
    }

    /** @param array{student_number: string, display_name: string, admission_date?: string|null, metadata?: array<string, mixed>|null} $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $student, array $input): array
    {
        $this->authorize($actor, $school, 'students.manage');

        return DB::transaction(function () use ($actor, $student, $input): array {
            $record = $this->find($student, true);
            $number = trim($input['student_number']);
            if (Student::query()->where('student_number', $number)->whereKeyNot($record->getKey())->exists()) {
                throw ValidationException::withMessages(['student_number' => ['The student number is already in use in this school.']]);
            }
            $record->fill(['student_number' => $number, 'display_name' => trim($input['display_name']), 'admission_date' => $input['admission_date'] ?? $record->admission_date, 'metadata' => $input['metadata'] ?? $record->metadata]);
            $record->save();
            $this->record($actor, 'student.updated', (string) $record->public_id, ['status' => (string) $record->status]);

            return $this->data($record);
        });
    }

    /** @return array<string, mixed> */
    public function transition(UserIdentity $actor, string $school, string $student, string $status): array
    {
        $this->authorize($actor, $school, 'students.manage');

        return DB::transaction(function () use ($actor, $student, $status): array {
            $record = $this->find($student, true);
            if ($record->status === $status) {
                return $this->data($record);
            }
            if (($record->status === 'archived') || ($record->status === 'withdrawn' && $status !== 'archived') || ($record->status === 'active' && $status !== 'withdrawn') || ($record->status === 'pending' && $status !== 'active')) {
                throw ValidationException::withMessages(['status' => ['The requested student lifecycle transition is not allowed.']]);
            }
            $record->update(['status' => $status]);
            $this->record($actor, 'student.'.$status, (string) $record->public_id, ['status' => $status]);

            return $this->data($record);
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function find(string $publicId, bool $lock = false): Student
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

    /** @param array<string, string> $transition */
    private function record(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'student', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['scope' => 'student_registry'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(Student $student): array
    {
        return ['id' => (string) $student->public_id, 'student_number' => (string) $student->student_number, 'display_name' => (string) $student->display_name, 'admission_date' => CarbonImmutable::parse((string) $student->admission_date)->toDateString(), 'status' => (string) $student->status];
    }
}
