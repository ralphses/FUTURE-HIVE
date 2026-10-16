<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Application\Contracts\CareerGuidanceProvider;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\Contracts\AiPrivacyBoundary;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class CareerGuidanceAction
{
    public function __construct(
        private readonly CareerGuidanceProvider $provider,
        private readonly AiPrivacyBoundary $privacy,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{schools: list<array<string, mixed>>} */
    public function index(UserIdentity $actor): array
    {
        $students = $this->students($actor);
        if ($students->isEmpty()) {
            throw new ModelNotFoundException;
        }

        $schools = $students->map(fn (object $student): array => $this->schoolProjection($student))->values()->all();
        $this->recordAudit($actor, count($schools), null);

        return ['schools' => $schools];
    }

    /**
     * @param  array{career_interest?: string|null, subject_interest?: string|null, institution_reference?: string|null}  $input
     * @return array<string, mixed>
     */
    public function suggest(UserIdentity $actor, array $input): array
    {
        $student = $this->students($actor)->first();
        if ($student === null) {
            throw new ModelNotFoundException;
        }

        $context = [
            'school' => ['id' => (string) $student->school_public_id, 'name' => (string) $student->school_name],
            'student' => ['id' => (string) $student->student_public_id, 'level' => $student->level_name === null ? null : (string) $student->level_name],
        ];
        $privacy = $this->privacy->prepare($actor, 'career_guidance', [
            'career_interest' => $input['career_interest'] ?? null,
            'subject_interest' => $input['subject_interest'] ?? null,
            'institution_reference' => $input['institution_reference'] ?? null,
            'student_level' => $context['student']['level'],
        ], true);
        $suggestion = $privacy['allowed']
            ? $this->provider->suggest($privacy['payload'], $context)
            : [
                'status' => 'unavailable',
                'suggestions' => [],
                'requirements' => [],
                'sources' => [],
                'issue_codes' => [$privacy['issue_code']],
                'provider_status' => $privacy['provider_status'],
            ];
        $this->recordAudit($actor, 1, (string) $student->school_public_id);

        return [
            'status' => (string) $suggestion['status'],
            'review_state' => 'unreviewed',
            'school' => $context['school'],
            'student' => ['id' => (string) $student->student_public_id],
            'context' => ['level' => $context['student']['level']],
            'suggestions' => $suggestion['suggestions'],
            'requirements' => $suggestion['requirements'],
            'sources' => $suggestion['sources'],
            'issue_codes' => $suggestion['issue_codes'],
            'provider_status' => $suggestion['provider_status'],
            'created_at' => now()->toISOString(),
        ];
    }

    /** @return Collection<int, \stdClass> */
    private function students(UserIdentity $actor): Collection
    {
        return DB::table('students')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->join('school_memberships', function ($join) use ($actor): void {
                $join->on('school_memberships.school_id', '=', 'students.school_id')
                    ->where('school_memberships.user_id', '=', $actor->getKey())
                    ->where('school_memberships.status', '=', 'active');
            })
            ->join('membership_roles', function ($join): void {
                $join->on('membership_roles.school_membership_id', '=', 'school_memberships.id')->whereNull('membership_roles.revoked_at');
            })
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'membership_roles.role_id')->where('roles.key', '=', 'student');
            })
            ->leftJoin('student_enrollments', function ($join): void {
                $join->on('student_enrollments.student_id', '=', 'students.id')
                    ->where('student_enrollments.status', '=', 'active');
            })
            ->leftJoin('academic_levels', 'academic_levels.id', '=', 'student_enrollments.academic_level_id')
            ->where('students.user_id', $actor->getKey())
            ->where('students.status', 'active')
            ->where('schools.status', 'active')
            ->select([
                'students.public_id as student_public_id',
                'schools.public_id as school_public_id',
                'schools.name as school_name',
                'academic_levels.name as level_name',
            ])
            ->distinct()
            ->orderBy('schools.id')
            ->orderBy('students.id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function schoolProjection(object $student): array
    {
        return [
            'school' => ['id' => (string) $student->school_public_id, 'name' => (string) $student->school_name],
            'context' => ['level' => $student->level_name === null ? null : (string) $student->level_name],
            'availability' => 'guidance_unavailable',
            'sources' => [],
            'issue_codes' => ['guidance_unavailable'],
        ];
    }

    private function recordAudit(UserIdentity $actor, int $count, ?string $school): void
    {
        $this->audit->execute(new AuditEventData(
            action: 'career_guidance.read',
            subjectType: 'career_guidance_projection',
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['caller_type' => 'student', 'result_count' => $count, 'school_scope' => $school],
        ));
    }
}
