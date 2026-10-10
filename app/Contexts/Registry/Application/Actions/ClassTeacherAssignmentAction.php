<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\ClassTeacherAssignment;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClassTeacherAssignmentAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $classArm): array
    {
        [$academicTerm, $academicClassArm] = $this->authorizeClassArm($actor, $school, $session, $term, $classArm, 'staff.class-teachers.read');
        $query = ClassTeacherAssignment::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('academic_class_arm_id', $academicClassArm->getKey())
            ->with('staffProfile.membership.identity')
            ->orderByDesc('effective_start')
            ->orderByDesc('id');

        if (! $this->canAdminister($actor)) {
            $query->whereHas('staffProfile.membership', fn ($membership): mixed => $membership->where('user_id', $actor->getKey()));
        }

        return ['items' => $query->get()->map(fn (ClassTeacherAssignment $assignment): array => $this->data($assignment))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $assignment): array
    {
        [$academicTerm, $academicClassArm] = $this->authorizeClassArm($actor, $school, $session, $term, $classArm, 'staff.class-teachers.read');

        return $this->data($this->find($assignment, $academicTerm, $academicClassArm, ! $this->canAdminister($actor), $actor));
    }

    /** @param array{staff_id: string, effective_start: string, effective_end?: string|null, reason?: string|null} $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $session, string $term, string $classArm, array $input): array
    {
        [$academicTerm, $academicClassArm] = $this->authorizeClassArm($actor, $school, $session, $term, $classArm, 'staff.class-teachers.manage');
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($actor, $academicTerm, $academicClassArm, $input): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $academicClassArm = AcademicClassArm::query()->whereKey($academicClassArm->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOpenStructure($academicTerm, $academicClassArm);
            $staff = $this->resolveTeacher((string) $input['staff_id']);
            [$start, $end] = $this->validatedDates($academicTerm, (string) $input['effective_start'], $input['effective_end'] ?? null);

            $existing = ClassTeacherAssignment::query()
                ->where('academic_term_id', $academicTerm->getKey())
                ->where('academic_class_arm_id', $academicClassArm->getKey())
                ->where('status', 'active')
                ->first();
            if ($existing instanceof ClassTeacherAssignment) {
                if ((int) $existing->staff_profile_id !== (int) $staff->getKey()) {
                    throw $this->invalidAssignment('This class arm already has an active class teacher for the term.');
                }

                return $this->data($existing->load('staffProfile.membership.identity'));
            }

            $this->assertTeacherAvailable($academicTerm, $staff, $start, $end);

            $assignment = ClassTeacherAssignment::query()->create([
                'school_id' => $academicClassArm->school_id,
                'academic_session_id' => $academicTerm->academic_session_id,
                'academic_term_id' => $academicTerm->getKey(),
                'academic_class_arm_id' => $academicClassArm->getKey(),
                'school_membership_id' => $staff->membership->getKey(),
                'staff_profile_id' => $staff->getKey(),
                'effective_start' => $start->toDateString(),
                'effective_end' => $end?->toDateString(),
                'status' => 'active',
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
                'reason' => $this->nullableString($input['reason'] ?? null),
            ]);
            $this->audit($actor, 'staff.class_teacher_assigned', (string) $assignment->public_id, ['from' => null, 'to' => 'active']);

            return $this->data($assignment->load('staffProfile.membership.identity'));
        });
    }

    /** @param array{effective_start?: string, effective_end?: string|null, reason?: string|null} $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $assignment, array $input): array
    {
        [$academicTerm, $academicClassArm] = $this->authorizeClassArm($actor, $school, $session, $term, $classArm, 'staff.class-teachers.manage');
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($actor, $academicTerm, $academicClassArm, $assignment, $input): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $academicClassArm = AcademicClassArm::query()->whereKey($academicClassArm->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOpenStructure($academicTerm, $academicClassArm);
            $record = $this->find($assignment, $academicTerm, $academicClassArm, false, $actor, true);
            if ($record->status === 'revoked') {
                throw $this->invalidAssignment('Revoked class-teacher assignments cannot be changed.');
            }
            [$start, $end] = $this->validatedDates(
                $academicTerm,
                $input['effective_start'] ?? CarbonImmutable::parse((string) $record->effective_start)->toDateString(),
                array_key_exists('effective_end', $input) ? $input['effective_end'] : ($record->effective_end === null ? null : CarbonImmutable::parse((string) $record->effective_end)->toDateString()),
            );
            $this->assertTeacherAvailable($academicTerm, $record->staffProfile, $start, $end, (int) $record->getKey());
            $record->fill(['effective_start' => $start->toDateString(), 'effective_end' => $end?->toDateString(), 'reason' => $this->nullableString($input['reason'] ?? $record->reason)]);
            $record->save();
            $this->audit($actor, 'staff.class_teacher_updated', (string) $record->public_id, ['from' => 'active', 'to' => 'active']);

            return $this->data($record->load('staffProfile.membership.identity'));
        });
    }

    /** @return array<string, mixed> */
    public function revoke(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $assignment, string $reason): array
    {
        [$academicTerm, $academicClassArm] = $this->authorizeClassArm($actor, $school, $session, $term, $classArm, 'staff.class-teachers.manage');
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($actor, $academicTerm, $academicClassArm, $assignment, $reason): array {
            $record = $this->find($assignment, $academicTerm, $academicClassArm, false, $actor, true);
            if ($record->status === 'revoked') {
                return $this->data($record->load('staffProfile.membership.identity'));
            }
            $record->fill(['status' => 'revoked', 'revoked_by' => $actor->getKey(), 'revoked_at' => now(), 'revoked_reason' => trim($reason)]);
            $record->save();
            $this->audit($actor, 'staff.class_teacher_revoked', (string) $record->public_id, ['from' => 'active', 'to' => 'revoked']);

            return $this->data($record->load('staffProfile.membership.identity'));
        });
    }

    /** @return array{0: AcademicTerm, 1: AcademicClassArm} */
    private function authorizeClassArm(UserIdentity $actor, string $school, string $session, string $term, string $classArm, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('public_id', $term)->whereHas('session', fn ($query): mixed => $query->where('public_id', $session)->where('school_id', $context->schoolId))->first();
        $academicClassArm = AcademicClassArm::query()->where('public_id', $classArm)->where('school_id', $context->schoolId)->whereHas('level', fn ($query): mixed => $query->where('status', 'active'))->whereHas('section', fn ($query): mixed => $query->where('status', 'active'))->first();
        if (! $academicTerm instanceof AcademicTerm || ! $academicClassArm instanceof AcademicClassArm || $academicTerm->status !== 'active' || $academicTerm->session?->status !== 'active') {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $academicClassArm];
    }

    private function assertAdministrator(UserIdentity $actor): void
    {
        $context = TenantContext::require();
        if ($context->membershipId === null || ! SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query): mixed => $query->whereNull('revoked_at')->whereHas('role', fn ($role): mixed => $role->whereIn('key', ['school_admin', 'proprietor'])))->exists()) {
            throw new ModelNotFoundException;
        }
    }

    private function canAdminister(UserIdentity $actor): bool
    {
        try {
            $this->assertAdministrator($actor);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    private function resolveTeacher(string $publicId): StaffProfile
    {
        $context = TenantContext::require();
        $staff = StaffProfile::query()->where('public_id', $publicId)->where('school_id', $context->schoolId)->where('employment_status', 'active')->whereHas('membership', fn ($query): mixed => $query->where('status', 'active')->whereHas('roleAssignments', fn ($roles): mixed => $roles->whereNull('revoked_at')->whereHas('role', fn ($role): mixed => $role->where('key', 'teacher'))))->with('membership.identity')->first();
        if (! $staff instanceof StaffProfile) {
            throw new ModelNotFoundException;
        }

        return $staff;
    }

    private function assertOpenStructure(AcademicTerm $term, AcademicClassArm $classArm): void
    {
        $term->loadMissing('session');
        if ($term->status !== 'active' || $term->session?->status !== 'active' || $classArm->status !== 'active') {
            throw $this->invalidAssignment('Class-teacher assignments require an active class arm and term.');
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable|null} */
    private function validatedDates(AcademicTerm $term, string $start, ?string $end): array
    {
        $effectiveStart = CarbonImmutable::parse($start);
        $effectiveEnd = $end === null || trim($end) === '' ? null : CarbonImmutable::parse($end);
        $termStart = CarbonImmutable::parse((string) $term->start_date);
        $termEnd = CarbonImmutable::parse((string) $term->end_date);
        if ($effectiveStart->lt($termStart) || $effectiveStart->gt($termEnd) || ($effectiveEnd !== null && ($effectiveEnd->lt($effectiveStart) || $effectiveEnd->gt($termEnd)))) {
            throw $this->invalidAssignment('Assignment dates must fall within the academic term.');
        }

        return [$effectiveStart, $effectiveEnd];
    }

    private function assertTeacherAvailable(AcademicTerm $term, StaffProfile $staff, CarbonImmutable $start, ?CarbonImmutable $end, ?int $exceptId = null): void
    {
        $endDate = $end?->toDateString() ?? CarbonImmutable::parse((string) $term->end_date)->toDateString();
        $query = ClassTeacherAssignment::query()->where('academic_term_id', $term->getKey())->where('staff_profile_id', $staff->getKey())->where('status', 'active')->where(function ($query) use ($start, $endDate): void {
            $query->where('effective_start', '<=', $endDate)->where(function ($nested) use ($start): void {
                $nested->whereNull('effective_end')->orWhere('effective_end', '>=', $start->toDateString());
            });
        });
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidAssignment('The teacher already has an overlapping class-teacher assignment.');
        }
    }

    private function find(string $publicId, AcademicTerm $term, AcademicClassArm $classArm, bool $teacherOnly, UserIdentity $actor, bool $lock = false): ClassTeacherAssignment
    {
        $query = ClassTeacherAssignment::query()->where('public_id', $publicId)->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $classArm->getKey())->with('staffProfile.membership.identity');
        if ($teacherOnly) {
            $query->whereHas('staffProfile.membership', fn ($membership): mixed => $membership->where('user_id', $actor->getKey()));
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $assignment = $query->first();
        if (! $assignment instanceof ClassTeacherAssignment) {
            throw new ModelNotFoundException;
        }

        return $assignment;
    }

    private function invalidAssignment(string $message): ValidationException
    {
        return ValidationException::withMessages(['class_teacher_assignment' => [$message]]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function data(ClassTeacherAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->public_id,
            'class_arm_id' => (string) $assignment->classArm?->public_id,
            'term_id' => (string) $assignment->term?->public_id,
            'staff_id' => (string) $assignment->staffProfile?->public_id,
            'staff_name' => (string) ($assignment->staffProfile?->preferred_name ?: $assignment->staffProfile?->legal_name),
            'effective_start' => CarbonImmutable::parse((string) $assignment->effective_start)->toDateString(),
            'effective_end' => $assignment->effective_end === null ? null : CarbonImmutable::parse((string) $assignment->effective_end)->toDateString(),
            'status' => (string) $assignment->status,
        ];
    }

    /** @param array<string, scalar|null> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'class_teacher_assignment', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'staff.class-teachers.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }
}
