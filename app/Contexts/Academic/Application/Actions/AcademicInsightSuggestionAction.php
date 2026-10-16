<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Application\Contracts\AcademicInsightAssistant;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentSubmission;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceEntry;
use App\Contexts\Academic\Domain\Models\AcademicAttendanceRegister;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Contracts\AiPrivacyBoundary;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class AcademicInsightSuggestionAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly AcademicInsightAssistant $assistant,
        private readonly AiPrivacyBoundary $privacy,
    ) {}

    /**
     * @param  array{session_id: string, term_id: string, offering_id: string, student_id?: string|null, insight_focus?: string|null}  $input
     * @return array<string, mixed>
     */
    public function suggest(UserIdentity $actor, string $school, array $input): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'teaching.lessons.manage')) {
            throw new ModelNotFoundException;
        }

        $term = AcademicTerm::query()
            ->where('school_id', $context->schoolId)
            ->where('public_id', $input['term_id'])
            ->whereHas('session', fn (Builder $query): Builder => $query->where('school_id', $context->schoolId)->where('public_id', $input['session_id']))
            ->with('session')
            ->first();
        $offering = AcademicSubjectOffering::query()
            ->where('school_id', $context->schoolId)
            ->where('public_id', $input['offering_id'])
            ->where('academic_term_id', $term?->getKey())
            ->with(['subject', 'level', 'classArm'])
            ->first();
        if (! $term instanceof AcademicTerm || ! $offering instanceof AcademicSubjectOffering || $term->status === 'closed' || $term->session?->status === 'closed' || $offering->status !== 'active') {
            throw new ModelNotFoundException;
        }

        $this->assertTeacherAssignmentOrLeadership($actor, $term, $offering);
        $student = $this->resolveStudent($context->schoolId, $term, $offering, $input['student_id'] ?? null);
        $privacy = $this->privacy->prepare($actor, 'academic_insights', [
            'subject' => (string) $offering->subject?->name,
            'level' => (string) $offering->level?->name,
            'class_arm' => (string) $offering->classArm?->name,
        ], true);
        if (! $privacy['allowed']) {
            return [
                'id' => (string) Str::uuid7(),
                'status' => 'not_available',
                'source' => ['session_id' => (string) $term->session?->public_id, 'term_id' => (string) $term->public_id, 'offering_id' => (string) $offering->public_id, 'subject' => (string) $offering->subject?->name, 'level' => (string) $offering->level?->name, 'class_arm' => (string) $offering->classArm?->name],
                'insight_focus' => $input['insight_focus'] ?? null,
                'confidence' => 'unavailable',
                'observations' => [],
                'supporting_indicators' => [],
                'issue_codes' => [$privacy['issue_code']],
                'provider_status' => $privacy['provider_status'],
                'created_at' => now()->toISOString(),
            ];
        }
        $indicators = $this->indicators($context->schoolId, $term, $offering, $student);
        $suggestion = $this->assistant->suggest($indicators);

        return [
            'id' => (string) Str::uuid7(),
            'status' => $suggestion['status'],
            'source' => [
                'session_id' => (string) $term->session?->public_id,
                'term_id' => (string) $term->public_id,
                'offering_id' => (string) $offering->public_id,
                'subject' => (string) $offering->subject?->name,
                'level' => (string) $offering->level?->name,
                'class_arm' => (string) $offering->classArm?->name,
                'student_id' => $student?->public_id,
            ],
            'insight_focus' => $input['insight_focus'] ?? null,
            ...$suggestion,
            'created_at' => now()->toISOString(),
        ];
    }

    private function assertTeacherAssignmentOrLeadership(UserIdentity $actor, AcademicTerm $term, AcademicSubjectOffering $offering): void
    {
        $context = TenantContext::require();
        $leadership = $context->membershipId !== null && SchoolMembership::query()->whereKey($context->membershipId)->where('user_id', $actor->getKey())->whereHas('roleAssignments', fn (Builder $query): Builder => $query->whereNull('revoked_at')->whereHas('role', fn (Builder $role): Builder => $role->whereIn('key', ['school_admin', 'principal', 'proprietor', 'hod_reviewer'])))->exists();
        if ($leadership) {
            return;
        }

        $assigned = AcademicTeachingAssignment::query()->where('school_id', $term->school_id)->where('academic_term_id', $term->getKey())->where('academic_subject_offering_id', $offering->getKey())->where('teacher_id', $actor->getKey())->where('status', 'active')->whereDate('effective_start', '<=', now()->toDateString())->where(fn (Builder $query): Builder => $query->whereNull('effective_end')->orWhereDate('effective_end', '>=', now()->toDateString()))->exists();
        if (! $assigned) {
            throw new ModelNotFoundException;
        }
    }

    private function resolveStudent(int|string $schoolId, AcademicTerm $term, AcademicSubjectOffering $offering, ?string $publicId): ?Student
    {
        if ($publicId === null) {
            return null;
        }

        $student = Student::query()->where('school_id', $schoolId)->where('public_id', $publicId)->whereHas('enrollments', fn (Builder $query): Builder => $query->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $offering->academic_class_arm_id)->where('status', 'active'))->first();
        if (! $student instanceof Student) {
            throw new ModelNotFoundException;
        }

        return $student;
    }

    /** @return array<string, int> */
    private function indicators(int|string $schoolId, AcademicTerm $term, AcademicSubjectOffering $offering, ?Student $student): array
    {
        $resultQuery = AcademicResultSnapshotEntry::query()->where('school_id', $schoolId)->where('academic_subject_offering_id', $offering->getKey())->where('status', 'computed')->whereNull('error_code')->whereHas('snapshot', fn (Builder $query): Builder => $query->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->where('status', 'published')->whereHas('gate', fn (Builder $gate): Builder => $gate->where('status', 'published')));
        if ($student instanceof Student) {
            $resultQuery->where('student_id', $student->getKey());
        }

        $registerQuery = AcademicAttendanceRegister::query()->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $offering->academic_class_arm_id)->whereIn('status', ['submitted', 'locked']);
        $attendanceQuery = AcademicAttendanceEntry::query()->where('school_id', $schoolId)->whereHas('register', fn (Builder $query): Builder => $query->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $offering->academic_class_arm_id)->whereIn('status', ['submitted', 'locked']));
        if ($student instanceof Student) {
            $attendanceQuery->where('student_id', $student->getKey());
        }

        $submissionQuery = AcademicAssessmentSubmission::query()->where('school_id', $schoolId)->where('academic_term_id', $term->getKey())->whereIn('status', ['submitted', 'approved'])->whereHas('instance', fn (Builder $query): Builder => $query->where('school_id', $schoolId)->where('academic_subject_offering_id', $offering->getKey()));

        return [
            'published_result_count' => $resultQuery->count(),
            'attendance_register_count' => $registerQuery->count(),
            'attendance_recorded_count' => $attendanceQuery->count(),
            'assessment_submission_count' => $submissionQuery->count(),
        ];
    }
}
