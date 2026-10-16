<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Application\Contracts\LessonPlanningAssistant;
use App\Contexts\Academic\Domain\Models\AcademicSchemeOfWork;
use App\Contexts\Academic\Domain\Models\AcademicSchemeTopic;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Contracts\AiPrivacyBoundary;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class LessonPlanningSuggestionAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly LessonPlanningAssistant $assistant,
        private readonly AiPrivacyBoundary $privacy,
    ) {}

    /**
     * @param  array{session_id: string, term_id: string, offering_id: string, topic_id?: string|null, objective_focus?: string|null, lesson_duration_minutes?: int|null}  $input
     * @return array<string, mixed>
     */
    public function suggest(UserIdentity $actor, string $school, array $input): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'teaching.lessons.manage')) {
            throw new ModelNotFoundException;
        }

        $term = AcademicTerm::query()->where('school_id', $context->schoolId)->where('public_id', $input['term_id'])->whereHas('session', fn ($query) => $query->where('school_id', $context->schoolId)->where('public_id', $input['session_id']))->with('session')->first();
        $offering = AcademicSubjectOffering::query()->where('school_id', $context->schoolId)->where('public_id', $input['offering_id'])->where('academic_term_id', $term?->getKey())->with(['subject', 'level', 'classArm'])->first();
        if (! $term instanceof AcademicTerm || ! $offering instanceof AcademicSubjectOffering || $term->status === 'closed' || $term->session?->status === 'closed' || $offering->status !== 'active') {
            throw new ModelNotFoundException;
        }

        $this->assertTeacherAssignmentOrLeadership($actor, $term, $offering);
        $topic = $this->resolveTopic($term, $offering, $input['topic_id'] ?? null);
        $prepared = $this->privacy->prepare($actor, 'lesson_planning', [
            'subject' => (string) $offering->subject?->name,
            'level' => (string) $offering->level?->name,
            'class_arm' => (string) $offering->classArm?->name,
            'topic' => $topic?->title,
            'objective_focus' => $input['objective_focus'] ?? null,
        ], false);
        $suggestion = $this->assistant->suggest($prepared['payload']);

        return [
            'id' => (string) Str::uuid7(),
            'status' => 'suggested_unreviewed',
            'source' => [
                'session_id' => (string) $term->session?->public_id,
                'term_id' => (string) $term->public_id,
                'offering_id' => (string) $offering->public_id,
                'subject' => (string) $offering->subject?->name,
                'level' => (string) $offering->level?->name,
                'class_arm' => (string) $offering->classArm?->name,
                'topic' => $topic?->title,
            ],
            'duration_minutes' => $input['lesson_duration_minutes'] ?? null,
            ...$suggestion,
            'created_at' => now()->toISOString(),
        ];
    }

    private function assertTeacherAssignmentOrLeadership(UserIdentity $actor, AcademicTerm $term, AcademicSubjectOffering $offering): void
    {
        $membershipId = TenantContext::require()->membershipId;
        $leadership = $membershipId !== null && SchoolMembership::query()->whereKey($membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn ($query) => $query->whereNull('revoked_at')->whereHas('role', fn ($role) => $role->whereIn('key', ['school_admin', 'principal', 'proprietor', 'hod_reviewer'])))->exists();
        if ($leadership) {
            return;
        }

        $assigned = AcademicTeachingAssignment::query()->where('school_id', $term->school_id)->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->where('teacher_id', $actor->getKey())->where('status', 'active')->whereDate('effective_start', '<=', now()->toDateString())->where(fn ($query) => $query->whereNull('effective_end')->orWhereDate('effective_end', '>=', now()->toDateString()))->exists();
        if (! $assigned) {
            throw new ModelNotFoundException;
        }
    }

    private function resolveTopic(AcademicTerm $term, AcademicSubjectOffering $offering, ?string $publicId): ?AcademicSchemeTopic
    {
        if ($publicId === null) {
            return null;
        }

        $scheme = AcademicSchemeOfWork::query()->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->first();
        $topic = $scheme instanceof AcademicSchemeOfWork ? $scheme->topics()->where('public_id', $publicId)->first() : null;
        if (! $topic instanceof AcademicSchemeTopic) {
            throw new ModelNotFoundException;
        }

        return $topic;
    }
}
