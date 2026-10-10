<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentProfile;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class StudentProfileAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorize($actor, $school, 'students.profile.read');

        $profile = StudentProfile::query()->where('student_id', $this->findStudent($student)->getKey())->first();
        if (! $profile instanceof StudentProfile) {
            throw new ModelNotFoundException;
        }

        return $this->data($profile, $student);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $student, array $input): array
    {
        $this->authorize($actor, $school, 'students.profile.manage');

        return DB::transaction(function () use ($actor, $student, $input): array {
            $studentRecord = $this->findStudent($student, true);
            $profile = StudentProfile::query()->where('student_id', $studentRecord->getKey())->lockForUpdate()->first();
            $profile ??= new StudentProfile(['school_id' => TenantContext::require()->schoolId, 'student_id' => $studentRecord->getKey()]);
            $before = $profile->exists ? $this->auditable($profile) : ['exists' => false];
            $profile->fill([
                'legal_name' => trim((string) $input['legal_name']),
                'preferred_name' => isset($input['preferred_name']) ? trim((string) $input['preferred_name']) : null,
                'date_of_birth' => $input['date_of_birth'] ?? null,
                'gender' => $input['gender'] ?? null,
                'notes' => isset($input['notes']) ? trim((string) $input['notes']) : null,
            ]);
            $profile->save();

            $this->audit->execute(new AuditEventData(
                action: $before['exists'] === false ? 'student.profile_created' : 'student.profile_updated',
                subjectType: 'student_profile',
                subjectPublicId: (string) $studentRecord->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'students.profile.manage'],
                stateTransition: ['from' => $before, 'to' => $this->auditable($profile)],
                requiresSchoolContext: true,
            ));

            return $this->data($profile, (string) $studentRecord->public_id);
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function findStudent(string $publicId, bool $lock = false): Student
    {
        $query = Student::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $student = $query->first();
        if (! $student instanceof Student) {
            throw new ModelNotFoundException;
        }

        return $student;
    }

    /** @return array<string, mixed> */
    private function data(StudentProfile $profile, string $studentPublicId): array
    {
        $rawDateOfBirth = $profile->getAttribute('date_of_birth');
        $dateOfBirth = $rawDateOfBirth === null ? null : CarbonImmutable::parse((string) $rawDateOfBirth)->toDateString();

        return [
            'student_id' => $studentPublicId,
            'legal_name' => (string) $profile->legal_name,
            'preferred_name' => $profile->preferred_name === null ? null : (string) $profile->preferred_name,
            'date_of_birth' => $dateOfBirth,
            'gender' => $profile->gender === null ? null : (string) $profile->gender,
            'notes' => $profile->notes === null ? null : (string) $profile->notes,
        ];
    }

    /** @return array{exists: bool, gender?: mixed, date_of_birth?: mixed} */
    private function auditable(StudentProfile $profile): array
    {
        return [
            'exists' => true,
            'gender' => $profile->gender === null ? null : (string) $profile->gender,
            'date_of_birth' => $profile->date_of_birth === null ? null : CarbonImmutable::parse((string) $profile->date_of_birth)->toDateString(),
        ];
    }
}
