<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GuardianRelationshipAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function guardians(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'guardians.read');

        $items = StudentGuardianRelationship::query()
            ->with('guardianProfile.identity')
            ->whereIn('status', ['pending', 'active'])
            ->get()
            ->unique('guardian_profile_id')
            ->map(fn (StudentGuardianRelationship $relationship): array => $this->guardianData($relationship))
            ->values()
            ->all();

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function showGuardian(UserIdentity $actor, string $school, string $guardian): array
    {
        $this->authorize($actor, $school, 'guardians.read');
        $profile = $this->profileForGuardian($guardian);
        $relationship = StudentGuardianRelationship::query()
            ->where('guardian_profile_id', $profile->getKey())
            ->whereIn('status', ['pending', 'active'])
            ->with('guardianProfile.identity')
            ->first();

        if (! $relationship instanceof StudentGuardianRelationship) {
            throw new ModelNotFoundException;
        }

        return $this->guardianData($relationship);
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function forStudent(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorize($actor, $school, 'guardian.links.read');
        $studentRecord = $this->findStudent($student);

        $items = StudentGuardianRelationship::query()
            ->where('student_id', $studentRecord->getKey())
            ->whereIn('status', ['pending', 'active'])
            ->with('guardianProfile.identity')
            ->get()
            ->map(fn (StudentGuardianRelationship $relationship): array => $this->relationshipData($relationship))
            ->all();

        return ['items' => $items];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $student, array $input): array
    {
        $this->authorize($actor, $school, 'guardian.links.manage');

        return DB::transaction(function () use ($actor, $student, $input): array {
            $studentRecord = $this->findStudent($student, true);
            $identity = UserIdentity::query()->where('public_id', (string) $input['guardian_id'])->first();
            if (! $identity instanceof UserIdentity) {
                throw new ModelNotFoundException;
            }

            $profile = GuardianProfile::query()->firstOrCreate(
                ['user_id' => $identity->getKey()],
                [
                    'display_name' => trim((string) ($input['display_name'] ?? $identity->name)),
                    'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : null,
                ],
            );

            $duplicate = StudentGuardianRelationship::query()
                ->where('student_id', $studentRecord->getKey())
                ->where('guardian_profile_id', $profile->getKey())
                ->where('relationship_type', (string) $input['relationship_type'])
                ->whereIn('status', ['pending', 'active'])
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['relationship' => ['An active relationship already exists.']]);
            }

            $relationship = StudentGuardianRelationship::query()->create([
                'school_id' => TenantContext::require()->schoolId,
                'student_id' => $studentRecord->getKey(),
                'guardian_profile_id' => $profile->getKey(),
                'relationship_type' => (string) $input['relationship_type'],
                'status' => 'pending',
                'created_by' => $actor->getAuthIdentifier(),
            ]);

            $this->audit->execute(new AuditEventData(
                action: 'guardian.relationship_created',
                subjectType: 'student_guardian_relationship',
                subjectPublicId: (string) $relationship->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'guardian.links.manage'],
                stateTransition: ['from' => null, 'to' => 'pending', 'relationship_type' => $relationship->relationship_type],
                requiresSchoolContext: true,
            ));

            return $this->relationshipData($relationship->load('guardianProfile.identity'));
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $student, string $relationship, array $input): array
    {
        $this->authorize($actor, $school, 'guardian.links.manage');

        return DB::transaction(function () use ($actor, $student, $relationship, $input): array {
            $studentRecord = $this->findStudent($student, true);
            $record = StudentGuardianRelationship::query()
                ->where('public_id', $relationship)
                ->where('student_id', $studentRecord->getKey())
                ->lockForUpdate()
                ->first();
            if (! $record instanceof StudentGuardianRelationship || $record->status === 'revoked') {
                throw new ModelNotFoundException;
            }

            $record->forceFill(['relationship_type' => (string) $input['relationship_type']])->save();
            $profile = $record->guardianProfile()->lockForUpdate()->firstOrFail();
            if (array_key_exists('display_name', $input) || array_key_exists('metadata', $input)) {
                $profile->forceFill([
                    'display_name' => trim((string) ($input['display_name'] ?? $profile->display_name)),
                    'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : $profile->metadata,
                ])->save();
            }

            $this->audit->execute(new AuditEventData(
                action: 'guardian.relationship_updated',
                subjectType: 'student_guardian_relationship',
                subjectPublicId: (string) $record->public_id,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'guardian.links.manage'],
                stateTransition: ['to' => 'pending', 'relationship_type' => $record->relationship_type],
                requiresSchoolContext: true,
            ));

            return $this->relationshipData($record->load('guardianProfile.identity'));
        });
    }

    /** @return array<string, mixed> */
    public function revoke(UserIdentity $actor, string $school, string $student, string $relationship, string $reason): array
    {
        $this->authorize($actor, $school, 'guardian.links.manage');

        return DB::transaction(function () use ($actor, $student, $relationship, $reason): array {
            $record = StudentGuardianRelationship::query()
                ->where('public_id', $relationship)
                ->where('student_id', $this->findStudent($student, true)->getKey())
                ->lockForUpdate()
                ->first();
            if (! $record instanceof StudentGuardianRelationship) {
                throw new ModelNotFoundException;
            }
            if ($record->status !== 'revoked') {
                $record->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoked_by' => $actor->getAuthIdentifier(),
                    'revocation_reason' => trim($reason),
                ])->save();
                $this->audit->execute(new AuditEventData(
                    action: 'guardian.relationship_revoked',
                    subjectType: 'student_guardian_relationship',
                    subjectPublicId: (string) $record->public_id,
                    schoolId: TenantContext::require()->schoolId,
                    actorId: (int) $actor->getAuthIdentifier(),
                    reason: trim($reason),
                    requestId: Context::get('request_id'),
                    authorizationContext: ['permission' => 'guardian.links.manage'],
                    stateTransition: ['from' => 'pending', 'to' => 'revoked'],
                    requiresSchoolContext: true,
                ));
            }

            return $this->relationshipData($record->load('guardianProfile.identity'));
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

    private function profileForGuardian(string $guardian): GuardianProfile
    {
        $identity = UserIdentity::query()->where('public_id', $guardian)->first();
        if (! $identity instanceof UserIdentity) {
            throw new ModelNotFoundException;
        }
        $profile = GuardianProfile::query()->where('user_id', $identity->getKey())->first();
        if (! $profile instanceof GuardianProfile) {
            throw new ModelNotFoundException;
        }

        return $profile;
    }

    /** @return array<string, mixed> */
    private function guardianData(StudentGuardianRelationship $relationship): array
    {
        $identity = $relationship->guardianProfile?->identity;

        return [
            'guardian_id' => (string) $identity?->public_id,
            'display_name' => (string) $relationship->guardianProfile?->display_name,
        ];
    }

    /** @return array<string, mixed> */
    private function relationshipData(StudentGuardianRelationship $relationship): array
    {
        return [
            'id' => (string) $relationship->public_id,
            'guardian_id' => (string) $relationship->guardianProfile?->identity?->public_id,
            'display_name' => (string) $relationship->guardianProfile?->display_name,
            'relationship_type' => (string) $relationship->relationship_type,
            'status' => (string) $relationship->status,
            'verified_at' => $this->verifiedAt($relationship),
        ];
    }

    private function verifiedAt(StudentGuardianRelationship $relationship): ?string
    {
        $value = $relationship->getRawOriginal('verified_at');

        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
