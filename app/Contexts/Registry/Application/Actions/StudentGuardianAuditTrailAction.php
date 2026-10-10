<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Registry\Domain\Models\GuardianInvitation;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollmentChange;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Contexts\Registry\Domain\Models\StudentPromotionCycle;
use App\Contexts\Registry\Domain\Models\StudentPromotionDecision;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class StudentGuardianAuditTrailAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function forStudent(UserIdentity $actor, string $school, string $student): LengthAwarePaginator
    {
        $this->authorize($actor, $school, 'students.audit.read');
        $record = $this->student($student);

        $subjects = [
            'student' => [(string) $record->public_id],
            'student_profile' => [(string) $record->public_id],
            'student_document' => $record->documents()->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
            'student_enrollment' => $record->enrollments()->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
            'student_enrollment_change' => StudentEnrollmentChange::query()->where('student_id', $record->getKey())->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
            'student_guardian_relationship' => $record->guardianRelationships()->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
            'guardian_invitation' => GuardianInvitation::query()->where('student_id', $record->getKey())->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
            'student_promotion_decision' => StudentPromotionDecision::query()->where('student_id', $record->getKey())->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
            'student_promotion_cycle' => StudentPromotionCycle::query()->whereHas('decisions', fn ($query) => $query->where('student_id', $record->getKey()))->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
        ];

        return $this->paginate($subjects);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function forGuardian(UserIdentity $actor, string $school, string $guardian): LengthAwarePaginator
    {
        $this->authorize($actor, $school, 'guardians.audit.read');
        $profile = GuardianProfile::query()->whereHas('identity', fn ($query) => $query->where('public_id', $guardian))->first();
        if (! $profile instanceof GuardianProfile) {
            throw new ModelNotFoundException;
        }

        $relationshipIds = StudentGuardianRelationship::query()
            ->where('school_id', TenantContext::require()->schoolId)
            ->where('guardian_profile_id', $profile->getKey())
            ->pluck('id');
        if ($relationshipIds->isEmpty()) {
            throw new ModelNotFoundException;
        }

        $subjects = [
            'guardian_profile' => [$guardian],
            'student_guardian_relationship' => StudentGuardianRelationship::query()->whereIn('id', $relationshipIds)->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
            'guardian_invitation' => GuardianInvitation::query()->whereIn('relationship_id', $relationshipIds)->pluck('public_id')->map(static fn ($id): string => (string) $id)->all(),
        ];

        return $this->paginate($subjects);
    }

    /**
     * @param  array<string, list<string>>  $subjects
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(array $subjects): LengthAwarePaginator
    {
        $query = AuditEvent::query()->where('school_id', TenantContext::require()->schoolId)->where(function ($query) use ($subjects): void {
            foreach ($subjects as $type => $publicIds) {
                if ($publicIds !== []) {
                    $query->orWhere(function ($nested) use ($type, $publicIds): void {
                        $nested->where('subject_type', $type)->whereIn('subject_public_id', $publicIds);
                    });
                }
            }
        })->orderByDesc('created_at')->orderByDesc('id');

        $paginator = $query->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $actors = UserIdentity::query()
            ->whereIn('id', $paginator->getCollection()->pluck('actor_id')->filter()->unique()->values())
            ->get(['id', 'public_id'])
            ->keyBy('id');
        $paginator->setCollection($paginator->getCollection()->map(fn (AuditEvent $event): array => $this->data($event, $actors)));

        return $paginator;
    }

    /**
     * @param  Collection<int|string, UserIdentity>  $actors
     * @return array<string, mixed>
     */
    private function data(AuditEvent $event, Collection $actors): array
    {
        $actor = $event->actor_id === null ? null : $actors->get($event->actor_id);

        return [
            'id' => (string) $event->public_id,
            'action' => (string) $event->action,
            'subject_type' => (string) $event->subject_type,
            'subject_id' => $event->subject_public_id,
            'reason' => $event->reason,
            'state_transition' => $this->safeTransition($event->state_transition),
            'actor_id' => $actor?->public_id,
            'request_id' => $event->request_id,
            'created_at' => optional($event->created_at)->toISOString(),
        ];
    }

    /** @return array<string, scalar|null> */
    private function safeTransition(mixed $transition): array
    {
        if (! is_array($transition)) {
            return [];
        }

        $safe = [];
        foreach (['from', 'to', 'status', 'version', 'change_type', 'relationship_type'] as $key) {
            if (array_key_exists($key, $transition) && (is_scalar($transition[$key]) || $transition[$key] === null)) {
                $safe[$key] = $transition[$key];
            }
        }

        return $safe;
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function student(string $publicId): Student
    {
        $student = Student::query()->where('public_id', $publicId)->first();
        if (! $student instanceof Student) {
            throw new ModelNotFoundException;
        }

        return $student;
    }
}
