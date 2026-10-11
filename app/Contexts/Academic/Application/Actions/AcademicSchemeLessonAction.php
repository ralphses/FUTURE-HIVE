<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicLesson;
use App\Contexts\Academic\Domain\Models\AcademicSchemeOfWork;
use App\Contexts\Academic\Domain\Models\AcademicSchemeTopic;
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

final class AcademicSchemeLessonAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function showScheme(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.schemes.read');
        $scheme = AcademicSchemeOfWork::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_subject_offering_id', $subjectOffering->getKey())->with('topics')->first();
        if (! $scheme instanceof AcademicSchemeOfWork) {
            throw new ModelNotFoundException;
        }

        return $this->schemeData($scheme);
    }

    /** @return array<string, mixed> */
    public function createScheme(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.schemes.manage');
        $this->assertTeacherOrAdministrator($actor, $subjectOffering, $academicTerm, 'teaching.schemes.manage');
        $this->assertOfferingOpen($academicTerm, $subjectOffering);

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering): array {
            $scheme = AcademicSchemeOfWork::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_subject_offering_id', $subjectOffering->getKey())->lockForUpdate()->first();
            if (! $scheme instanceof AcademicSchemeOfWork) {
                $scheme = AcademicSchemeOfWork::query()->create([
                    'school_id' => TenantContext::require()->schoolId,
                    'academic_session_id' => $academicTerm->academic_session_id,
                    'academic_term_id' => $academicTerm->getKey(),
                    'academic_level_id' => $subjectOffering->academic_level_id,
                    'academic_section_id' => $subjectOffering->academic_section_id,
                    'academic_class_arm_id' => $subjectOffering->academic_class_arm_id,
                    'academic_subject_offering_id' => $subjectOffering->getKey(),
                    'status' => 'active',
                    'created_by' => $actor->getKey(),
                ]);
                $this->audit($actor, 'academic.scheme.created', (string) $scheme->public_id, ['status' => 'active'], 'teaching.schemes.manage');
            }

            return $this->schemeData($scheme->load('topics'));
        });
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function topics(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        return ['items' => $this->scheme($actor, $school, $session, $term, $offering)->topics()->orderBy('sequence')->get()->map(fn (AcademicSchemeTopic $topic): array => $this->topicData($topic))->all()];
    }

    /** @param array{title: string, description?: string|null, sequence: int, teaching_week?: int|null} $input
     * @return array<string, mixed>
     */
    public function createTopic(UserIdentity $actor, string $school, string $session, string $term, string $offering, array $input): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.schemes.manage');
        $this->assertTeacherOrAdministrator($actor, $subjectOffering, $academicTerm, 'teaching.schemes.manage');
        $this->assertOfferingOpen($academicTerm, $subjectOffering);

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $input): array {
            $scheme = $this->schemeForOffering($academicTerm, $subjectOffering, true);
            $topic = AcademicSchemeTopic::query()->create([
                'school_id' => TenantContext::require()->schoolId,
                'academic_scheme_of_work_id' => $scheme->getKey(),
                'title' => trim((string) $input['title']),
                'description' => $this->nullableString($input['description'] ?? null),
                'sequence' => (int) $input['sequence'],
                'teaching_week' => isset($input['teaching_week']) ? (int) $input['teaching_week'] : null,
                'status' => 'planned',
            ]);
            $this->audit($actor, 'academic.scheme_topic.created', (string) $topic->public_id, ['status' => 'planned'], 'teaching.schemes.manage');

            return $this->topicData($topic);
        });
    }

    /** @param array{title?: string, description?: string|null, sequence?: int, teaching_week?: int|null} $input
     * @return array<string, mixed>
     */
    public function updateTopic(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $topic, array $input): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.schemes.manage');
        $this->assertTeacherOrAdministrator($actor, $subjectOffering, $academicTerm, 'teaching.schemes.manage');
        $this->assertOfferingOpen($academicTerm, $subjectOffering);

        return DB::transaction(function () use ($actor, $academicTerm, $subjectOffering, $topic, $input): array {
            $scheme = $this->schemeForOffering($academicTerm, $subjectOffering, true);
            $record = AcademicSchemeTopic::query()->where('public_id', $topic)->where('academic_scheme_of_work_id', $scheme->getKey())->lockForUpdate()->first();
            if (! $record instanceof AcademicSchemeTopic || $record->status === 'completed') {
                throw new ModelNotFoundException;
            }
            $record->fill(array_filter([
                'title' => array_key_exists('title', $input) ? trim((string) $input['title']) : null,
                'description' => array_key_exists('description', $input) ? $this->nullableString($input['description']) : null,
                'sequence' => array_key_exists('sequence', $input) ? (int) $input['sequence'] : null,
                'teaching_week' => array_key_exists('teaching_week', $input) ? ($input['teaching_week'] === null ? null : (int) $input['teaching_week']) : null,
            ], static fn (mixed $value): bool => $value !== null));
            $record->updated_by = $actor->getKey();
            $record->save();
            $this->audit($actor, 'academic.scheme_topic.updated', (string) $record->public_id, ['status' => $record->status], 'teaching.schemes.manage');

            return $this->topicData($record);
        });
    }

    /** @return array<string, mixed> */
    public function progressTopic(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $topic, string $status): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.schemes.manage');
        $this->assertTeacherOrAdministrator($actor, $subjectOffering, $academicTerm, 'teaching.schemes.manage');
        if (! in_array($status, ['planned', 'in_progress', 'completed'], true)) {
            throw ValidationException::withMessages(['status' => ['The topic status is invalid.']]);
        }
        $scheme = $this->schemeForOffering($academicTerm, $subjectOffering);
        $record = AcademicSchemeTopic::query()->where('public_id', $topic)->where('academic_scheme_of_work_id', $scheme->getKey())->first();
        if (! $record instanceof AcademicSchemeTopic) {
            throw new ModelNotFoundException;
        }
        $record->fill(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null, 'updated_by' => $actor->getKey()])->save();
        $this->audit($actor, 'academic.scheme_topic.progressed', (string) $record->public_id, ['status' => $status], 'teaching.schemes.manage');

        return $this->topicData($record);
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function lessons(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.lessons.read');
        $query = AcademicLesson::query()->where('academic_term_id', $academicTerm->getKey())->where('academic_subject_offering_id', $subjectOffering->getKey())->with(['offering.subject', 'offering.classArm', 'topic', 'teacher'])->orderByDesc('lesson_date')->orderByDesc('id');
        if (! $this->canReview($actor)) {
            $query->where('teacher_id', $actor->getKey());
        }

        return ['items' => $query->get()->map(fn (AcademicLesson $lesson): array => $this->lessonData($lesson))->all()];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createLesson(UserIdentity $actor, string $school, string $session, string $term, string $offering, array $input): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.lessons.manage');
        $this->assertOfferingOpen($academicTerm, $subjectOffering);
        $assignment = $this->resolveTeacherAssignment($actor, $academicTerm, $subjectOffering);
        $topic = $this->resolveTopic($academicTerm, $subjectOffering, $input['topic_id'] ?? null);
        $lesson = AcademicLesson::query()->create($this->lessonAttributes($actor, $academicTerm, $subjectOffering, $assignment, $topic, $input));
        $this->audit($actor, 'academic.lesson.created', (string) $lesson->public_id, ['status' => 'draft'], 'teaching.lessons.manage');

        return $this->lessonData($lesson->load(['offering.subject', 'offering.classArm', 'topic', 'teacher']));
    }

    /** @return array<string, mixed> */
    public function showLesson(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $lesson): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.lessons.read');

        return $this->lessonData($this->findLesson($lesson, $academicTerm, $subjectOffering, ! $this->canReview($actor), $actor));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateLesson(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $lesson, array $input): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.lessons.manage');
        $this->assertOfferingOpen($academicTerm, $subjectOffering);
        $record = $this->findLesson($lesson, $academicTerm, $subjectOffering, true, $actor, true);
        if (! in_array($record->status, ['draft', 'changes_requested'], true)) {
            throw $this->invalidLesson('Only draft or returned lessons can be edited.');
        }
        $record->fill($this->lessonFields($input));
        $record->updated_by = $actor->getKey();
        $record->save();
        $this->audit($actor, 'academic.lesson.updated', (string) $record->public_id, ['status' => $record->status], 'teaching.lessons.manage');

        return $this->lessonData($record->load(['offering.subject', 'offering.classArm', 'topic', 'teacher']));
    }

    /** @return array<string, mixed> */
    public function submitLesson(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $lesson): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.lessons.manage');
        $record = $this->findLesson($lesson, $academicTerm, $subjectOffering, true, $actor, true);
        if ($record->status === 'submitted') {
            return $this->lessonData($record);
        }
        if (! in_array($record->status, ['draft', 'changes_requested'], true)) {
            throw $this->invalidLesson('This lesson cannot be submitted in its current state.');
        }
        $record->fill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_snapshot' => $this->snapshot($record), 'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => null])->save();
        $this->audit($actor, 'academic.lesson.submitted', (string) $record->public_id, ['status' => 'submitted'], 'teaching.lessons.manage');

        return $this->lessonData($record);
    }

    /** @return array<string, mixed> */
    public function reviewLesson(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $lesson, string $decision, ?string $reason = null): array
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.lessons.review');
        $this->assertReviewer($actor);
        if (! in_array($decision, ['approved', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['decision' => ['The lesson review decision is invalid.']]);
        }
        $record = $this->findLesson($lesson, $academicTerm, $subjectOffering, false, $actor, true);
        if ($record->status === $decision || ($record->status === 'approved' && $decision === 'approved')) {
            return $this->lessonData($record);
        }
        if ($record->status !== 'submitted') {
            throw $this->invalidLesson('Only submitted lessons can be reviewed.');
        }
        if ($decision === 'changes_requested' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required when returning a lesson.']]);
        }
        $record->fill(['status' => $decision, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_reason' => $this->nullableString($reason)])->save();
        $this->audit($actor, 'academic.lesson.reviewed', (string) $record->public_id, ['from' => 'submitted', 'to' => $decision], 'teaching.lessons.review');

        return $this->lessonData($record);
    }

    /** @return array{0: AcademicTerm, 1: AcademicSubjectOffering} */
    private function authorizeOffering(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId, string $offeringPublicId, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $termPublicId)->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $sessionPublicId))->first();
        $subjectOffering = AcademicSubjectOffering::query()->where('school_id', $context->schoolId)->where('public_id', $offeringPublicId)->where('academic_term_id', $academicTerm?->getKey())->first();
        if (! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering) {
            throw new ModelNotFoundException;
        }

        return [$academicTerm, $subjectOffering];
    }

    private function schemeForOffering(AcademicTerm $term, AcademicSubjectOffering $offering, bool $lock = false): AcademicSchemeOfWork
    {
        $query = AcademicSchemeOfWork::query()->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $scheme = $query->first();
        if (! $scheme instanceof AcademicSchemeOfWork) {
            throw new ModelNotFoundException;
        }

        return $scheme;
    }

    private function scheme(UserIdentity $actor, string $school, string $session, string $term, string $offering): AcademicSchemeOfWork
    {
        [$academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'teaching.schemes.read');

        return $this->schemeForOffering($academicTerm, $subjectOffering);
    }

    private function resolveTeacherAssignment(UserIdentity $actor, AcademicTerm $term, AcademicSubjectOffering $offering): AcademicTeachingAssignment
    {
        $assignment = AcademicTeachingAssignment::query()->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->where('teacher_id', $actor->getKey())->where('status', 'active')->whereDate('effective_start', '<=', now()->toDateString())->where(function ($query): void {
            $query->whereNull('effective_end')->orWhereDate('effective_end', '>=', now()->toDateString());
        })->first();
        if (! $assignment instanceof AcademicTeachingAssignment) {
            throw new ModelNotFoundException;
        }

        return $assignment;
    }

    private function resolveTopic(AcademicTerm $term, AcademicSubjectOffering $offering, mixed $publicId): ?AcademicSchemeTopic
    {
        if ($publicId === null) {
            return null;
        }
        $scheme = $this->schemeForOffering($term, $offering);
        $topic = AcademicSchemeTopic::query()->where('public_id', (string) $publicId)->where('academic_scheme_of_work_id', $scheme->getKey())->first();
        if (! $topic instanceof AcademicSchemeTopic) {
            throw new ModelNotFoundException;
        }

        return $topic;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function lessonAttributes(UserIdentity $actor, AcademicTerm $term, AcademicSubjectOffering $offering, AcademicTeachingAssignment $assignment, ?AcademicSchemeTopic $topic, array $input): array
    {
        return array_merge(['school_id' => TenantContext::require()->schoolId, 'academic_session_id' => $term->academic_session_id, 'academic_term_id' => $term->getKey(), 'academic_class_arm_id' => $offering->academic_class_arm_id, 'academic_subject_offering_id' => $offering->getKey(), 'academic_teaching_assignment_id' => $assignment->getKey(), 'teacher_id' => $actor->getKey(), 'academic_scheme_topic_id' => $topic?->getKey(), 'created_by' => $actor->getKey(), 'status' => 'draft'], $this->lessonFields($input));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function lessonFields(array $input): array
    {
        return array_filter(['lesson_date' => $input['lesson_date'] ?? null, 'teaching_week' => isset($input['teaching_week']) ? (int) $input['teaching_week'] : null, 'title' => isset($input['title']) ? trim((string) $input['title']) : null, 'objectives' => $this->nullableString($input['objectives'] ?? null), 'materials' => $this->nullableString($input['materials'] ?? null), 'activities' => $this->nullableString($input['activities'] ?? null), 'evaluation' => $this->nullableString($input['evaluation'] ?? null), 'assignment' => $this->nullableString($input['assignment'] ?? null)], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function snapshot(AcademicLesson $lesson): array
    {
        return array_intersect_key($lesson->toArray(), array_flip(['title', 'objectives', 'materials', 'activities', 'evaluation', 'assignment', 'lesson_date', 'teaching_week', 'academic_scheme_topic_id']));
    }

    private function findLesson(string $publicId, AcademicTerm $term, AcademicSubjectOffering $offering, bool $teacherOnly, UserIdentity $actor, bool $lock = false): AcademicLesson
    {
        $query = AcademicLesson::query()->where('public_id', $publicId)->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->with(['offering.subject', 'offering.classArm', 'topic', 'teacher']);
        if ($teacherOnly) {
            $query->where('teacher_id', $actor->getKey());
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->first();
        if (! $record instanceof AcademicLesson) {
            throw new ModelNotFoundException;
        }

        return $record;
    }

    private function assertOfferingOpen(AcademicTerm $term, AcademicSubjectOffering $offering): void
    {
        $term->loadMissing('session');
        if ($term->status === 'closed' || $term->session?->status === 'closed' || $offering->status !== 'active') {
            throw $this->invalidLesson('The selected offering or academic term is not open.');
        }
    }

    private function assertTeacherOrAdministrator(UserIdentity $actor, AcademicSubjectOffering $offering, AcademicTerm $term, string $permission): void
    {
        try {
            $this->assertAdministrator($actor);

            return;
        } catch (ModelNotFoundException) {
        }
        $this->resolveTeacherAssignment($actor, $term, $offering);
    }

    private function assertAdministrator(UserIdentity $actor): void
    {
        $context = TenantContext::require();
        if ($context->membershipId === null || ! SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'principal', 'proprietor', 'hod_reviewer'])))->exists()) {
            throw new ModelNotFoundException;
        }
    }

    private function assertReviewer(UserIdentity $actor): void
    {
        $this->assertAdministrator($actor);
    }

    private function canReview(UserIdentity $actor): bool
    {
        try {
            $this->assertReviewer($actor);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    private function invalidLesson(string $message): ValidationException
    {
        return ValidationException::withMessages(['lesson' => [$message]]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function schemeData(AcademicSchemeOfWork $scheme): array
    {
        return ['id' => (string) $scheme->public_id, 'term_id' => (string) $scheme->term?->public_id, 'offering_id' => (string) $scheme->offering?->public_id, 'status' => (string) $scheme->status, 'topics' => $scheme->topics->sortBy('sequence')->values()->map(fn (AcademicSchemeTopic $topic): array => $this->topicData($topic))->all()];
    }

    /** @return array<string, mixed> */
    private function topicData(AcademicSchemeTopic $topic): array
    {
        return ['id' => (string) $topic->public_id, 'title' => (string) $topic->title, 'description' => $topic->description, 'sequence' => (int) $topic->sequence, 'teaching_week' => $topic->teaching_week === null ? null : (int) $topic->teaching_week, 'status' => (string) $topic->status, 'completed_at' => $this->dateTimeString($topic->completed_at)];
    }

    /** @return array<string, mixed> */
    private function lessonData(AcademicLesson $lesson): array
    {
        return ['id' => (string) $lesson->public_id, 'offering_id' => (string) $lesson->offering?->public_id, 'class_arm_id' => (string) $lesson->offering?->classArm?->public_id, 'subject_name' => (string) $lesson->offering?->subject?->name, 'topic_id' => $lesson->topic?->public_id, 'teacher_id' => (string) $lesson->teacher?->public_id, 'teacher_name' => (string) $lesson->teacher?->name, 'lesson_date' => $this->dateString($lesson->lesson_date), 'teaching_week' => $lesson->teaching_week, 'title' => (string) $lesson->title, 'objectives' => $lesson->objectives, 'materials' => $lesson->materials, 'activities' => $lesson->activities, 'evaluation' => $lesson->evaluation, 'assignment' => $lesson->assignment, 'status' => (string) $lesson->status, 'submitted_at' => $this->dateTimeString($lesson->submitted_at), 'reviewed_at' => $this->dateTimeString($lesson->reviewed_at), 'review_reason' => $lesson->review_reason];
    }

    private function dateString(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toDateString();
    }

    private function dateTimeString(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    /** @param array<string, mixed> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition, string $permission): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_teaching_record', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => $permission], stateTransition: $transition, requiresSchoolContext: true));
    }
}
