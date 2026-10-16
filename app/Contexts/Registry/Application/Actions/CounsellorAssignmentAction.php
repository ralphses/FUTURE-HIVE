<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\CounsellorAssignment;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class CounsellorAssignmentAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'counselling.assignments.read');
        $query = CounsellorAssignment::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->with(['staffProfile', 'student'])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');

        if (! $this->isLeadership($actor)) {
            $query->whereHas('membership', fn ($membership): mixed => $membership->where('user_id', $actor->getKey()));
        }

        return ['items' => $query->get()->map(fn (CounsellorAssignment $assignment): array => $this->data($assignment))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $assignment): array
    {
        $this->authorize($actor, $school, 'counselling.assignments.read');
        $query = CounsellorAssignment::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('public_id', $assignment)
            ->with(['staffProfile', 'student']);
        if (! $this->isLeadership($actor)) {
            $query->whereHas('membership', fn ($membership): mixed => $membership->where('user_id', $actor->getKey()));
        }

        $record = $query->first();
        if (! $record instanceof CounsellorAssignment) {
            throw new ModelNotFoundException;
        }

        return $this->data($record);
    }

    /** @param array{staff_id: string, student_id: string, reason?: string|null} $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'counselling.assignments.manage');

        return DB::transaction(function () use ($actor, $input): array {
            $context = TenantContext::require();
            $staff = StaffProfile::query()
                ->where('school_id', $context->schoolId)
                ->where('public_id', (string) $input['staff_id'])
                ->lockForUpdate()
                ->first();
            if (! $staff instanceof StaffProfile || ! $this->isActiveCounsellor($staff)) {
                throw new ModelNotFoundException;
            }

            $student = Student::query()
                ->where('school_id', $context->schoolId)
                ->where('public_id', (string) $input['student_id'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if (! $student instanceof Student) {
                throw new ModelNotFoundException;
            }

            $enrollment = StudentEnrollment::query()
                ->where('school_id', $context->schoolId)
                ->where('student_id', $student->getKey())
                ->where('status', 'active')
                ->orderByDesc('start_date')
                ->lockForUpdate()
                ->first();
            if (! $enrollment instanceof StudentEnrollment) {
                throw new ModelNotFoundException;
            }

            $existing = CounsellorAssignment::query()
                ->where('school_id', $context->schoolId)
                ->where('staff_profile_id', $staff->getKey())
                ->where('student_id', $student->getKey())
                ->where('status', 'active')
                ->with(['staffProfile', 'student'])
                ->first();
            if ($existing instanceof CounsellorAssignment) {
                return $this->data($existing);
            }

            $assignment = CounsellorAssignment::query()->create([
                'school_id' => $context->schoolId,
                'staff_profile_id' => $staff->getKey(),
                'school_membership_id' => $staff->school_membership_id,
                'student_id' => $student->getKey(),
                'student_enrollment_id' => $enrollment->getKey(),
                'status' => 'active',
                'active_assignment_key' => $context->schoolId.':'.$staff->getKey().':'.$student->getKey(),
                'assigned_by' => (int) $actor->getAuthIdentifier(),
                'assigned_at' => now(),
                'reason' => $this->nullableString($input['reason'] ?? null),
                'request_id' => Context::get('request_id'),
            ])->load(['staffProfile', 'student']);
            $this->audit($actor, 'counselling.assignment_assigned', (string) $assignment->public_id, ['from' => null, 'to' => 'active']);

            return $this->data($assignment);
        });
    }

    /** @return array<string, mixed> */
    public function revoke(UserIdentity $actor, string $school, string $assignment, string $reason): array
    {
        $this->authorize($actor, $school, 'counselling.assignments.manage');

        return DB::transaction(function () use ($actor, $assignment, $reason): array {
            $record = CounsellorAssignment::query()
                ->where('school_id', TenantContext::require()->schoolId)
                ->where('public_id', $assignment)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof CounsellorAssignment) {
                throw new ModelNotFoundException;
            }
            if ($record->status === 'revoked') {
                return $this->data($record->load(['staffProfile', 'student']));
            }

            $record->fill([
                'status' => 'revoked',
                'active_assignment_key' => null,
                'revoked_by' => (int) $actor->getAuthIdentifier(),
                'revoked_at' => now(),
                'revoked_reason' => trim($reason),
            ]);
            $record->save();
            $this->audit($actor, 'counselling.assignment_revoked', (string) $record->public_id, ['from' => 'active', 'to' => 'revoked']);

            return $this->data($record->load(['staffProfile', 'student']));
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function isLeadership(UserIdentity $actor): bool
    {
        return $this->permissions->allows($actor, TenantContext::require()->schoolPublicId, 'counselling.assignments.manage');
    }

    private function isActiveCounsellor(StaffProfile $staff): bool
    {
        $staff->loadMissing('membership.roleAssignments.role');

        return $staff->employment_status === 'active'
            && $staff->membership?->status === 'active'
            && $staff->membership->roleAssignments->contains(fn ($assignment): bool => $assignment->revoked_at === null && $assignment->role?->key === 'counsellor');
    }

    /** @return array<string, mixed> */
    private function data(CounsellorAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->public_id,
            'staff_id' => (string) $assignment->staffProfile?->public_id,
            'staff_name' => (string) ($assignment->staffProfile?->preferred_name ?: $assignment->staffProfile?->legal_name),
            'student_id' => (string) $assignment->student?->public_id,
            'student_number' => (string) $assignment->student?->student_number,
            'student_name' => (string) $assignment->student?->display_name,
            'status' => (string) $assignment->status,
            'assigned_at' => CarbonImmutable::parse((string) $assignment->assigned_at)->toISOString(),
            'revoked_at' => $assignment->revoked_at === null ? null : CarbonImmutable::parse((string) $assignment->revoked_at)->toISOString(),
        ];
    }

    /** @param array<string, scalar|null> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'counsellor_assignment', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'counselling.assignments.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
