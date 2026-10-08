<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicTeachingAssignmentAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assignments.read');
        $query = AcademicTeachingAssignment::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('academic_subject_offering_id', $subjectOffering->getKey())
            ->with('teacher')
            ->orderBy('effective_start')
            ->orderBy('id');

        if (! $this->canAdminister($actor)) {
            $query->where('teacher_id', $actor->getKey());
        }

        return ['items' => $query->get()->map(fn (AcademicTeachingAssignment $assignment): array => $this->data($assignment))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $assignment): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assignments.read');

        return $this->data($this->find($assignment, $academicTerm, $subjectOffering, ! $this->canAdminister($actor), $actor));
    }

    /** @param array{teacher_id: string, effective_start: string, effective_end?: string|null, reason?: string|null} $data
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $session, string $term, string $offering, array $data): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assignments.manage');
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $data): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $subjectOffering = AcademicSubjectOffering::query()->whereKey($subjectOffering->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOfferingOpen($academicTerm, $subjectOffering);
            $teacher = $this->resolveTeacher((string) $data['teacher_id']);
            [$start, $end] = $this->validatedDates($academicTerm, (string) $data['effective_start'], $data['effective_end'] ?? null);
            $this->assertNoOverlap($subjectOffering, $teacher, $start, $end);

            $assignment = AcademicTeachingAssignment::query()->create([
                'academic_session_id' => $academicTerm->academic_session_id,
                'academic_term_id' => $academicTerm->getKey(),
                'academic_subject_offering_id' => $subjectOffering->getKey(),
                'teacher_id' => $teacher->getKey(),
                'effective_start' => $start->toDateString(),
                'effective_end' => $end?->toDateString(),
                'status' => 'active',
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
                'reason' => $this->nullableString($data['reason'] ?? null),
            ]);
            $this->audit($actor, 'academic.teaching_assignment_created', (string) $assignment->public_id, ['status' => 'active']);

            return $this->data($assignment->load('teacher'));
        });
    }

    /** @param array{effective_start?: string, effective_end?: string|null, reason?: string|null} $data
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $assignment, array $data): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assignments.manage');
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $assignment, $data): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $subjectOffering = AcademicSubjectOffering::query()->whereKey($subjectOffering->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOfferingOpen($academicTerm, $subjectOffering);
            $record = $this->find($assignment, $academicTerm, $subjectOffering, false, $actor, true);
            if ($record->status === 'revoked') {
                throw $this->invalidAssignment('Revoked assignments cannot be changed.');
            }
            [$start, $end] = $this->validatedDates($academicTerm, $data['effective_start'] ?? CarbonImmutable::parse((string) $record->effective_start)->toDateString(), $data['effective_end'] ?? ($record->effective_end === null ? null : CarbonImmutable::parse((string) $record->effective_end)->toDateString()));
            $this->assertNoOverlap($subjectOffering, $record->teacher, $start, $end, (int) $record->getKey());
            $record->fill(['effective_start' => $start->toDateString(), 'effective_end' => $end?->toDateString(), 'reason' => $this->nullableString($data['reason'] ?? $record->reason)]);
            $record->save();
            $this->audit($actor, 'academic.teaching_assignment_updated', (string) $record->public_id, ['status' => $record->status]);

            return $this->data($record->load('teacher'));
        });
    }

    /** @return array<string, mixed> */
    public function revoke(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $assignment, string $reason): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assignments.manage');
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $assignment, $reason): array {
            $record = $this->find($assignment, $academicTerm, $subjectOffering, false, $actor, true);
            if ($record->status === 'revoked') {
                return $this->data($record->load('teacher'));
            }
            $record->fill(['status' => 'revoked', 'revoked_by' => $actor->getKey(), 'revoked_at' => now(), 'revoked_reason' => trim($reason)]);
            $record->save();
            $this->audit($actor, 'academic.teaching_assignment_revoked', (string) $record->public_id, ['status' => 'revoked']);

            return $this->data($record->load('teacher'));
        });
    }

    /** @return array{0: AcademicTerm, 1: AcademicSubjectOffering} */
    private function authorizeOffering(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId, string $offeringPublicId, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('public_id', $termPublicId)->whereHas('session', fn ($query) => $query->where('public_id', $sessionPublicId))->first();
        $subjectOffering = AcademicSubjectOffering::query()->where('public_id', $offeringPublicId)->where('academic_term_id', $academicTerm?->getKey())->first();
        if (! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $subjectOffering];
    }

    private function assertAdministrator(UserIdentity $actor): void
    {
        $context = TenantContext::require();
        if ($context->membershipId === null || ! SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer'])))->exists()) {
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

    private function resolveTeacher(string $publicId): UserIdentity
    {
        $context = TenantContext::require();
        $teacher = UserIdentity::query()->where('public_id', $publicId)->whereHas('schoolMemberships', fn ($query) => $query->where('school_id', $context->schoolId)->where('status', 'active')->whereHas('roleAssignments', fn ($roles) => $roles->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->where('key', 'teacher'))))->first();
        if (! $teacher instanceof UserIdentity) {
            throw new ModelNotFoundException;
        }

        return $teacher;
    }

    private function assertOfferingOpen(AcademicTerm $term, AcademicSubjectOffering $offering): void
    {
        $term->loadMissing('session');
        if ($term->status === 'closed' || $term->session?->status === 'closed' || $offering->status !== 'active') {
            throw $this->invalidAssignment('Assignments require an active offering and open term.');
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

    private function assertNoOverlap(AcademicSubjectOffering $offering, UserIdentity $teacher, CarbonImmutable $start, ?CarbonImmutable $end, ?int $exceptId = null): void
    {
        $endDate = $end?->toDateString() ?? CarbonImmutable::parse((string) $offering->term->end_date)->toDateString();
        $query = AcademicTeachingAssignment::query()->where('academic_subject_offering_id', $offering->getKey())->where('teacher_id', $teacher->getKey())->where('status', 'active')->where(function ($query) use ($start, $endDate): void {
            $query->where('effective_start', '<=', $endDate)->where(function ($nested) use ($start): void {
                $nested->whereNull('effective_end')->orWhere('effective_end', '>=', $start->toDateString());
            });
        });
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidAssignment('The teacher already has an overlapping assignment for this offering.');
        }
    }

    private function find(string $publicId, AcademicTerm $term, AcademicSubjectOffering $offering, bool $teacherOnly, UserIdentity $actor, bool $lock = false): AcademicTeachingAssignment
    {
        $query = AcademicTeachingAssignment::query()->where('public_id', $publicId)->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->with('teacher');
        if ($teacherOnly) {
            $query->where('teacher_id', $actor->getKey());
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $assignment = $query->first();
        if (! $assignment instanceof AcademicTeachingAssignment) {
            throw new ModelNotFoundException;
        }

        return $assignment;
    }

    private function invalidAssignment(string $message): ValidationException
    {
        return ValidationException::withMessages(['academic_teaching_assignment' => [$message]]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function data(AcademicTeachingAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->public_id,
            'offering_id' => (string) $assignment->offering?->public_id,
            'term_id' => (string) $assignment->term?->public_id,
            'teacher_id' => (string) $assignment->teacher?->public_id,
            'teacher_name' => (string) $assignment->teacher?->name,
            'effective_start' => CarbonImmutable::parse((string) $assignment->effective_start)->toDateString(),
            'effective_end' => $assignment->effective_end === null ? null : CarbonImmutable::parse((string) $assignment->effective_end)->toDateString(),
            'status' => (string) $assignment->status,
        ];
    }

    /** @param array<string, scalar|null> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_teaching_assignment', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.assignments.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }
}
