<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\ClassTeacherAssignment;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final class TeacherWorklistAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor, string $school, int $page, int $perPage): LengthAwarePaginator
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'teaching.worklist.read')) {
            throw new ModelNotFoundException;
        }

        $membership = SchoolMembership::query()
            ->whereKey($context->membershipId)
            ->where('school_id', $context->schoolId)
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->where('key', 'teacher')))
            ->first();
        if (! $membership instanceof SchoolMembership) {
            throw new ModelNotFoundException;
        }

        $staffProfile = StaffProfile::query()
            ->where('school_id', $context->schoolId)
            ->where('school_membership_id', $membership->getKey())
            ->where('employment_status', 'active')
            ->first();
        if (! $staffProfile instanceof StaffProfile) {
            throw new ModelNotFoundException;
        }

        $items = AcademicTeachingAssignment::query()
            ->where('school_id', $context->schoolId)
            ->where('teacher_id', $actor->getKey())
            ->where('status', 'active')
            ->with(['session', 'term', 'offering.classArm', 'offering.subject'])
            ->get()
            ->map(fn (AcademicTeachingAssignment $assignment): array => $this->subjectItem($assignment))
            ->all();

        $items = array_merge($items, ClassTeacherAssignment::query()
            ->where('school_id', $context->schoolId)
            ->where('staff_profile_id', $staffProfile->getKey())
            ->where('status', 'active')
            ->with(['session', 'term', 'classArm'])
            ->get()
            ->map(fn (ClassTeacherAssignment $assignment): array => $this->classTeacherItem($assignment))
            ->all());

        usort($items, static fn (array $left, array $right): int => [$left['effective_start'], $left['assignment_type'], $left['assignment_id']] <=> [$right['effective_start'], $right['assignment_type'], $right['assignment_id']]);

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paginator = new LengthAwarePaginator(array_slice($items, $offset, $perPage), $total, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);

        return $paginator;
    }

    /** @return array<string, mixed> */
    private function subjectItem(AcademicTeachingAssignment $assignment): array
    {
        $offering = $assignment->offering;

        return [
            'assignment_type' => 'subject',
            'assignment_id' => (string) $assignment->public_id,
            'session' => ['id' => (string) $assignment->session?->public_id, 'name' => (string) $assignment->session?->name],
            'term' => ['id' => (string) $assignment->term?->public_id, 'name' => (string) $assignment->term?->name],
            'class_arm' => ['id' => (string) $offering?->classArm?->public_id, 'name' => (string) $offering?->classArm?->name],
            'subject_offering' => ['id' => (string) $offering?->public_id, 'subject' => ['id' => (string) $offering?->subject?->public_id, 'name' => (string) $offering?->subject?->name]],
            'effective_start' => CarbonImmutable::parse((string) $assignment->effective_start)->toDateString(),
            'effective_end' => $assignment->effective_end === null ? null : CarbonImmutable::parse((string) $assignment->effective_end)->toDateString(),
            'status' => (string) $assignment->status,
            'pending_actions' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function classTeacherItem(ClassTeacherAssignment $assignment): array
    {
        return [
            'assignment_type' => 'class_teacher',
            'assignment_id' => (string) $assignment->public_id,
            'session' => ['id' => (string) $assignment->session?->public_id, 'name' => (string) $assignment->session?->name],
            'term' => ['id' => (string) $assignment->term?->public_id, 'name' => (string) $assignment->term?->name],
            'class_arm' => ['id' => (string) $assignment->classArm?->public_id, 'name' => (string) $assignment->classArm?->name],
            'subject_offering' => null,
            'effective_start' => CarbonImmutable::parse((string) $assignment->effective_start)->toDateString(),
            'effective_end' => $assignment->effective_end === null ? null : CarbonImmutable::parse((string) $assignment->effective_end)->toDateString(),
            'status' => (string) $assignment->status,
            'pending_actions' => [],
        ];
    }
}
