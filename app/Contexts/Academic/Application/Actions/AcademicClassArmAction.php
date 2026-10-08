<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicClassArmAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId): array
    {
        [$level, $section] = $this->authorizeParent($actor, $school, $levelPublicId, $sectionPublicId, 'academic.class-arms.read');

        return ['items' => AcademicClassArm::query()
            ->where('academic_level_id', $level->getKey())
            ->where('academic_section_id', $section->getKey())
            ->orderBy('name')
            ->get()
            ->map(fn (AcademicClassArm $classArm): array => $this->data($classArm))
            ->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId, string $classArmPublicId): array
    {
        [$level, $section] = $this->authorizeParent($actor, $school, $levelPublicId, $sectionPublicId, 'academic.class-arms.read');

        return $this->data($this->findClassArm($classArmPublicId, $level, $section));
    }

    /** @param array{name: string, code?: string|null, capacity: int} $data
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId, array $data): array
    {
        [$level, $section] = $this->authorizeParent($actor, $school, $levelPublicId, $sectionPublicId, 'academic.class-arms.manage');
        $this->assertParentsActive($level, $section);

        return DB::transaction(function () use ($actor, $level, $section, $data): array {
            $this->assertUnique($level, $section, $data['name'], $data['code'] ?? null);
            $classArm = AcademicClassArm::query()->create([
                'academic_level_id' => $level->getKey(),
                'academic_section_id' => $section->getKey(),
                'name' => trim($data['name']),
                'code' => $this->nullableString($data['code'] ?? null),
                'capacity' => $data['capacity'],
                'status' => 'active',
            ]);
            $this->audit($actor, 'academic.class_arm_created', (string) $classArm->public_id, ['status' => 'active', 'capacity' => (int) $classArm->capacity]);

            return $this->data($classArm);
        });
    }

    /** @param array{name: string, code?: string|null, capacity: int} $data
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId, string $classArmPublicId, array $data): array
    {
        [$level, $section] = $this->authorizeParent($actor, $school, $levelPublicId, $sectionPublicId, 'academic.class-arms.manage');

        return DB::transaction(function () use ($actor, $level, $section, $classArmPublicId, $data): array {
            $classArm = $this->findClassArm($classArmPublicId, $level, $section, true);
            $this->assertUnique($level, $section, $data['name'], $data['code'] ?? null, (int) $classArm->getKey());
            $classArm->fill(['name' => trim($data['name']), 'code' => $this->nullableString($data['code'] ?? null), 'capacity' => $data['capacity']]);
            $classArm->save();
            $this->audit($actor, 'academic.class_arm_updated', (string) $classArm->public_id, ['status' => $classArm->status, 'capacity' => (int) $classArm->capacity]);

            return $this->data($classArm);
        });
    }

    /** @return array<string, mixed> */
    public function transition(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId, string $classArmPublicId, string $status): array
    {
        [$level, $section] = $this->authorizeParent($actor, $school, $levelPublicId, $sectionPublicId, 'academic.class-arms.manage');

        return DB::transaction(function () use ($actor, $level, $section, $classArmPublicId, $status): array {
            if ($status === 'active') {
                $this->assertParentsActive($level, $section);
            }
            $classArm = $this->findClassArm($classArmPublicId, $level, $section, true);
            if ((string) $classArm->status === $status) {
                return $this->data($classArm);
            }
            $classArm->status = $status;
            $classArm->save();
            $this->audit($actor, 'academic.class_arm_'.$status, (string) $classArm->public_id, ['status' => $status]);

            return $this->data($classArm);
        });
    }

    /** @return array{0: AcademicLevel, 1: AcademicSection} */
    private function authorizeParent(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $level = AcademicLevel::query()->where('public_id', $levelPublicId)->first();
        $section = AcademicSection::query()->where('public_id', $sectionPublicId)->first();
        if (! $level instanceof AcademicLevel || ! $section instanceof AcademicSection || (int) $section->academic_level_id !== (int) $level->getKey()) {
            throw new ModelNotFoundException;
        }

        return [$level, $section];
    }

    private function assertParentsActive(AcademicLevel $level, AcademicSection $section): void
    {
        if ($level->status !== 'active' || $section->status !== 'active') {
            throw $this->invalidClassArm('Class arms require active academic levels and sections.');
        }
    }

    private function findClassArm(string $publicId, AcademicLevel $level, AcademicSection $section, bool $lock = false): AcademicClassArm
    {
        $query = AcademicClassArm::query()->where('public_id', $publicId)->where('academic_level_id', $level->getKey())->where('academic_section_id', $section->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $classArm = $query->first();
        if (! $classArm instanceof AcademicClassArm) {
            throw new ModelNotFoundException;
        }

        return $classArm;
    }

    private function assertUnique(AcademicLevel $level, AcademicSection $section, string $name, ?string $code, ?int $exceptId = null): void
    {
        $query = AcademicClassArm::query()->where('academic_level_id', $level->getKey())->where('academic_section_id', $section->getKey())->where(function ($query) use ($name, $code): void {
            $query->where('name', trim($name));
            if ($code !== null && trim($code) !== '') {
                $query->orWhere('code', trim($code));
            }
        });
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidClassArm('The class-arm name or code is already in use.');
        }
    }

    private function invalidClassArm(string $message): ValidationException
    {
        return ValidationException::withMessages(['academic_class_arm' => [$message]]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_class_arm', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.class-arms.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(AcademicClassArm $classArm): array
    {
        return ['id' => (string) $classArm->public_id, 'level_id' => (string) $classArm->level?->public_id, 'section_id' => (string) $classArm->section?->public_id, 'name' => (string) $classArm->name, 'code' => $this->nullableString($classArm->getAttribute('code')), 'capacity' => (int) $classArm->capacity, 'status' => (string) $classArm->status];
    }
}
