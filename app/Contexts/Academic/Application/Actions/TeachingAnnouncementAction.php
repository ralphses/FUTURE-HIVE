<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Academic\Domain\Models\TeachingAnnouncement;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\ClassTeacherAssignment;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeachingAnnouncementAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{items: list<array<string, mixed>>} */
    /** @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>}
     */
    public function index(UserIdentity $actor, string $school, array $filters = []): array
    {
        $context = $this->authorizeContext($actor, $school, 'teaching.announcements.read');
        $query = TeachingAnnouncement::query()->where('school_id', $context->schoolId)->with(['term.session', 'classArm', 'offering.subject', 'author'])->latest('id');
        if (isset($filters['term_id'])) {
            $term = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $filters['term_id'])->first();
            if (! $term instanceof AcademicTerm) {
                throw new ModelNotFoundException;
            }
            $query->where('academic_term_id', $term->getKey());
        }
        if (! $this->isAdministrator($actor)) {
            $query->where(function ($scope) use ($actor): void {
                $scope->where('author_id', $actor->getKey())->orWhereIn('academic_class_arm_id', $this->teacherClassArmIds($actor))->orWhereIn('academic_subject_offering_id', $this->teacherOfferingIds($actor));
            });
        }

        return ['items' => $query->get()->map(fn (TeachingAnnouncement $announcement): array => $this->data($announcement))->all()];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, array $input): array
    {
        $context = $this->authorizeContext($actor, $school, 'teaching.announcements.manage');
        [$session, $term, $classArm, $offering] = $this->resolveAudience($context->schoolId, $input);
        $this->assertOpen($session, $term, $offering, $classArm);
        if (! $this->isAdministrator($actor)) {
            $this->assertTeacherScope($actor, $term, $classArm, $offering);
        }
        $announcement = TeachingAnnouncement::query()->create(['school_id' => $context->schoolId, 'academic_session_id' => $session->getKey(), 'academic_term_id' => $term->getKey(), 'academic_class_arm_id' => $classArm?->getKey(), 'academic_subject_offering_id' => $offering?->getKey(), 'author_id' => $actor->getKey(), 'title' => trim((string) $input['title']), 'body' => trim((string) $input['body']), 'status' => 'draft', 'created_by' => $actor->getKey()]);
        $this->audit($actor, 'teaching.announcement.created', (string) $announcement->public_id, ['to' => 'draft'], 'teaching.announcements.manage');

        return $this->data($announcement->load(['term.session', 'classArm', 'offering.subject', 'author']));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $announcement, array $input): array
    {
        $record = $this->findForMutation($actor, $school, $announcement, 'teaching.announcements.manage');
        if (! $this->isAdministrator($actor) && $record->author_id !== $actor->getKey()) {
            throw new ModelNotFoundException;
        }
        if (! in_array($record->status, ['draft', 'rejected'], true)) {
            throw $this->invalid('Only draft or rejected announcements can be edited.');
        }
        $record->fill(array_filter(['title' => array_key_exists('title', $input) ? trim((string) $input['title']) : null, 'body' => array_key_exists('body', $input) ? trim((string) $input['body']) : null], static fn (mixed $value): bool => $value !== null));
        $record->updated_by = $actor->getKey();
        $record->save();
        $this->audit($actor, 'teaching.announcement.updated', (string) $record->public_id, ['status' => $record->status], 'teaching.announcements.manage');

        return $this->data($record->load(['term.session', 'classArm', 'offering.subject', 'author']));
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $announcement): array
    {
        $context = $this->authorizeContext($actor, $school, 'teaching.announcements.read');
        $record = TeachingAnnouncement::query()->where('school_id', $context->schoolId)->where('public_id', $announcement)->with(['term.session', 'classArm', 'offering.subject', 'author'])->first();
        if (! $record instanceof TeachingAnnouncement || (! $this->isAdministrator($actor) && ! $this->canAccessAudience($actor, $record))) {
            throw new ModelNotFoundException;
        }

        return $this->data($record);
    }

    /** @return array<string, mixed> */
    public function transition(UserIdentity $actor, string $school, string $announcement, string $transition, ?string $reason = null): array
    {
        $permission = match ($transition) {
            'submit' => 'teaching.announcements.manage', 'approve', 'reject' => 'teaching.announcements.review', 'publish', 'recall' => 'teaching.announcements.publish', default => throw new ModelNotFoundException
        };
        $record = $this->findForMutation($actor, $school, $announcement, $permission);
        if ($transition === 'submit' && ! $this->isAdministrator($actor)) {
            if ($record->author_id !== $actor->getKey()) {
                throw new ModelNotFoundException;
            }
            $this->assertAudienceAuthority($actor, $record);
        }
        if (in_array($transition, ['approve', 'reject', 'publish', 'recall'], true)) {
            $this->assertAdministrator($actor);
        }

        return DB::transaction(function () use ($actor, $record, $transition, $reason, $permission): array {
            $record = TeachingAnnouncement::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $from = (string) $record->status;
            $target = match ($transition) {
                'submit' => 'submitted', 'approve' => 'approved', 'publish' => 'published', 'recall' => 'recalled', 'reject' => 'rejected'
            };
            if ($from === $target) {
                return $this->data($record);
            }
            $allowed = match ($transition) {
                'submit' => ['draft', 'rejected'], 'approve' => ['submitted'], 'publish' => ['approved'], 'recall' => ['published'], 'reject' => ['submitted']
            };
            if (! in_array($from, $allowed, true)) {
                throw $this->invalid('This announcement cannot make that transition.');
            }
            if (in_array($transition, ['reject', 'recall'], true) && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
            }
            $now = now();
            $fields = ['status' => $target, 'updated_by' => $actor->getKey()];
            if ($target === 'submitted') {
                $fields += ['submitted_at' => $now, 'submitted_by' => $actor->getKey()];
            }
            if ($target === 'approved') {
                $fields += ['approved_at' => $now, 'approved_by' => $actor->getKey()];
            }
            if ($target === 'published') {
                $fields += ['published_at' => $now, 'published_by' => $actor->getKey()];
            }
            if ($target === 'recalled') {
                $fields += ['recalled_at' => $now, 'recalled_by' => $actor->getKey(), 'review_reason' => trim((string) $reason)];
            }
            if ($target === 'rejected') {
                $fields += ['rejected_at' => $now, 'rejected_by' => $actor->getKey(), 'review_reason' => trim((string) $reason)];
            }
            $record->fill($fields)->save();
            $this->audit($actor, 'teaching.announcement.'.$transition, (string) $record->public_id, ['from' => $from, 'to' => $target], $permission);

            return $this->data($record);
        });
    }

    private function findForMutation(UserIdentity $actor, string $school, string $publicId, string $permission): TeachingAnnouncement
    {
        $context = $this->authorizeContext($actor, $school, $permission);
        $record = TeachingAnnouncement::query()->where('school_id', $context->schoolId)->where('public_id', $publicId)->with(['term.session', 'classArm', 'offering'])->first();
        if (! $record instanceof TeachingAnnouncement || (! $this->isAdministrator($actor) && $record->author_id !== $actor->getKey() && ! $this->canAccessAudience($actor, $record))) {
            throw new ModelNotFoundException;
        }

        return $record;
    }

    /** @return array{0: AcademicSession, 1: AcademicTerm, 2: AcademicClassArm|null, 3: AcademicSubjectOffering|null} */
    /** @param array<string, mixed> $input
     * @return array{0: AcademicSession, 1: AcademicTerm, 2: AcademicClassArm|null, 3: AcademicSubjectOffering|null}
     */
    private function resolveAudience(int $schoolId, array $input): array
    {
        $session = AcademicSession::query()->where('school_id', $schoolId)->where('public_id', $input['session_id'])->first();
        $term = AcademicTerm::query()->where('school_id', $schoolId)->where('public_id', $input['term_id'])->where('academic_session_id', $session?->getKey())->first();
        $classArm = isset($input['class_arm_id']) ? AcademicClassArm::query()->where('school_id', $schoolId)->where('public_id', $input['class_arm_id'])->first() : null;
        $offering = isset($input['subject_offering_id']) ? AcademicSubjectOffering::query()->where('school_id', $schoolId)->where('public_id', $input['subject_offering_id'])->where('academic_term_id', $term?->getKey())->first() : null;
        if (! $session instanceof AcademicSession || ! $term instanceof AcademicTerm || (isset($input['class_arm_id']) && ! $classArm instanceof AcademicClassArm) || (isset($input['subject_offering_id']) && ! $offering instanceof AcademicSubjectOffering)) {
            throw new ModelNotFoundException;
        }
        if ($offering instanceof AcademicSubjectOffering && $classArm instanceof AcademicClassArm && $offering->academic_class_arm_id !== $classArm->getKey()) {
            throw new ModelNotFoundException;
        }

        return [$session, $term, $classArm, $offering];
    }

    private function authorizeContext(UserIdentity $actor, string $school, string $permission): TenantContext
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }

        return $context;
    }

    private function assertOpen(AcademicSession $session, AcademicTerm $term, ?AcademicSubjectOffering $offering, ?AcademicClassArm $classArm): void
    {
        if ($session->status === 'closed' || $term->status === 'closed' || ($offering instanceof AcademicSubjectOffering && $offering->status !== 'active') || ($classArm instanceof AcademicClassArm && $classArm->status !== 'active')) {
            throw $this->invalid('The selected academic audience is not open.');
        }
    }

    private function assertAudienceAuthority(UserIdentity $actor, TeachingAnnouncement $record): void
    {
        if ($this->isAdministrator($actor) || $this->canAccessAudience($actor, $record)) {
            return;
        }
        throw new ModelNotFoundException;
    }

    private function assertTeacherScope(UserIdentity $actor, AcademicTerm $term, ?AcademicClassArm $classArm, ?AcademicSubjectOffering $offering): void
    {
        $hasOfferingScope = $offering instanceof AcademicSubjectOffering && AcademicTeachingAssignment::query()->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->where('teacher_id', $actor->getKey())->where('status', 'active')->whereDate('effective_start', '<=', now()->toDateString())->where(function ($query): void {
            $query->whereNull('effective_end')->orWhereDate('effective_end', '>=', now()->toDateString());
        })->exists();
        $membershipId = TenantContext::require()->membershipId;
        $hasClassScope = $classArm instanceof AcademicClassArm && $membershipId !== null && ClassTeacherAssignment::query()->where('school_membership_id', $membershipId)->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $classArm->getKey())->where('status', 'active')->whereDate('effective_start', '<=', now()->toDateString())->where(function ($query): void {
            $query->whereNull('effective_end')->orWhereDate('effective_end', '>=', now()->toDateString());
        })->exists();
        if (! $hasOfferingScope && ! $hasClassScope) {
            throw new ModelNotFoundException;
        }
    }

    private function canAccessAudience(UserIdentity $actor, TeachingAnnouncement $record): bool
    {
        return in_array($record->author_id, [$actor->getKey()], true) || in_array($record->academic_subject_offering_id, $this->teacherOfferingIds($actor), true) || in_array($record->academic_class_arm_id, $this->teacherClassArmIds($actor), true);
    }

    /** @return list<int> */
    private function teacherOfferingIds(UserIdentity $actor): array
    {
        return AcademicTeachingAssignment::query()->where('teacher_id', $actor->getKey())->where('status', 'active')->pluck('academic_subject_offering_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function teacherClassArmIds(UserIdentity $actor): array
    {
        $membership = TenantContext::require()->membershipId;
        if ($membership === null) {
            return [];
        }

        return ClassTeacherAssignment::query()->where('school_membership_id', $membership)->where('status', 'active')->pluck('academic_class_arm_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    private function isAdministrator(UserIdentity $actor): bool
    {
        try {
            $this->assertAdministrator($actor);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    private function assertAdministrator(UserIdentity $actor): void
    {
        $membershipId = TenantContext::require()->membershipId;
        if ($membershipId === null || ! SchoolMembership::query()->whereKey($membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'principal', 'proprietor', 'hod_reviewer'])))->exists()) {
            throw new ModelNotFoundException;
        }
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['announcement' => [$message]]);
    }

    /** @return array<string, mixed> */
    private function data(TeachingAnnouncement $announcement): array
    {
        return ['id' => (string) $announcement->public_id, 'session_id' => (string) $announcement->term?->session?->public_id, 'term_id' => (string) $announcement->term?->public_id, 'class_arm_id' => $announcement->classArm?->public_id, 'subject_offering_id' => $announcement->offering?->public_id, 'subject_name' => $announcement->offering?->subject?->name, 'author_id' => (string) $announcement->author?->public_id, 'author_name' => $announcement->author?->name, 'title' => (string) $announcement->title, 'body' => (string) $announcement->body, 'status' => (string) $announcement->status, 'review_reason' => $announcement->review_reason, 'submitted_at' => $this->dateTimeString($announcement->submitted_at), 'approved_at' => $this->dateTimeString($announcement->approved_at), 'published_at' => $this->dateTimeString($announcement->published_at), 'recalled_at' => $this->dateTimeString($announcement->recalled_at), 'rejected_at' => $this->dateTimeString($announcement->rejected_at)];
    }

    private function dateTimeString(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    /** @param array<string, string> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition, string $permission): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'teaching_announcement', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => $permission], stateTransition: $transition, requiresSchoolContext: true));
    }
}
