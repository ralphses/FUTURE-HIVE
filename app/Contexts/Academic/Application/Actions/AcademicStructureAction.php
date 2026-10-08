<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

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

final class AcademicStructureAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function levels(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'academic.structure.read');

        return ['items' => AcademicLevel::query()->orderBy('sequence')->orderBy('name')->get()->map(fn (AcademicLevel $level): array => $this->levelData($level))->all()];
    }

    /** @return array<string, mixed> */
    public function level(UserIdentity $actor, string $school, string $level): array
    {
        $this->authorize($actor, $school, 'academic.structure.read');

        return $this->levelData($this->findLevel($level));
    }

    /** @param array{name: string, code?: string|null, sequence: int, stage?: string|null} $data
     * @return array<string, mixed>
     */
    public function createLevel(UserIdentity $actor, string $school, array $data): array
    {
        $this->authorize($actor, $school, 'academic.structure.manage');
        $this->assertLevelUnique($data['name'], $data['code'] ?? null, $data['sequence']);

        return DB::transaction(function () use ($actor, $data): array {
            $level = AcademicLevel::query()->create([
                'name' => trim($data['name']), 'code' => $this->nullableString($data['code'] ?? null),
                'sequence' => $data['sequence'], 'stage' => $this->nullableString($data['stage'] ?? null), 'status' => 'active',
            ]);
            $this->audit($actor, 'academic.level_created', (string) $level->public_id, ['status' => 'active']);

            return $this->levelData($level);
        });
    }

    /** @param array{name: string, code?: string|null, sequence: int, stage?: string|null} $data
     * @return array<string, mixed>
     */
    public function updateLevel(UserIdentity $actor, string $school, string $publicId, array $data): array
    {
        $this->authorize($actor, $school, 'academic.structure.manage');

        return DB::transaction(function () use ($actor, $publicId, $data): array {
            $level = $this->findLevel($publicId, true);
            $this->assertLevelUnique($data['name'], $data['code'] ?? null, $data['sequence'], (int) $level->getKey());
            $level->fill(['name' => trim($data['name']), 'code' => $this->nullableString($data['code'] ?? null), 'sequence' => $data['sequence'], 'stage' => $this->nullableString($data['stage'] ?? null)]);
            $level->save();
            $this->audit($actor, 'academic.level_updated', (string) $level->public_id, ['status' => $level->status]);

            return $this->levelData($level);
        });
    }

    /** @return array<string, mixed> */
    public function transitionLevel(UserIdentity $actor, string $school, string $publicId, string $status): array
    {
        $this->authorize($actor, $school, 'academic.structure.manage');

        return DB::transaction(function () use ($actor, $publicId, $status): array {
            $level = $this->findLevel($publicId, true);
            if ((string) $level->status === $status) {
                return $this->levelData($level);
            }
            $level->status = $status;
            $level->save();
            $this->audit($actor, 'academic.level_'.$status, (string) $level->public_id, ['status' => $status]);

            return $this->levelData($level);
        });
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function sections(UserIdentity $actor, string $school, string $levelPublicId): array
    {
        $this->authorize($actor, $school, 'academic.structure.read');
        $level = $this->findLevel($levelPublicId);

        return ['items' => $level->sections()->orderBy('sequence')->orderBy('name')->get()->map(fn (AcademicSection $section): array => $this->sectionData($section))->all()];
    }

    /** @return array<string, mixed> */
    public function section(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId): array
    {
        $this->authorize($actor, $school, 'academic.structure.read');
        $level = $this->findLevel($levelPublicId);
        $section = $this->findSection($sectionPublicId);
        $this->assertSectionBelongsToLevel($section, $level);

        return $this->sectionData($section);
    }

    /** @param array{name: string, code?: string|null, sequence: int} $data
     * @return array<string, mixed>
     */
    public function createSection(UserIdentity $actor, string $school, string $levelPublicId, array $data): array
    {
        $this->authorize($actor, $school, 'academic.structure.manage');

        return DB::transaction(function () use ($actor, $levelPublicId, $data): array {
            $level = $this->findLevel($levelPublicId, true);
            if ($level->status !== 'active') {
                throw $this->invalidStructure('Sections cannot be added to an inactive level.');
            }
            $this->assertSectionUnique($level, $data['name'], $data['code'] ?? null, $data['sequence']);
            $section = $level->sections()->create(['name' => trim($data['name']), 'code' => $this->nullableString($data['code'] ?? null), 'sequence' => $data['sequence'], 'status' => 'active']);
            $this->audit($actor, 'academic.section_created', (string) $section->public_id, ['level_id' => (string) $level->public_id, 'status' => 'active']);

            return $this->sectionData($section);
        });
    }

    /** @param array{name: string, code?: string|null, sequence: int} $data
     * @return array<string, mixed>
     */
    public function updateSection(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId, array $data): array
    {
        $this->authorize($actor, $school, 'academic.structure.manage');

        return DB::transaction(function () use ($actor, $levelPublicId, $sectionPublicId, $data): array {
            $level = $this->findLevel($levelPublicId, true);
            $section = $this->findSection($sectionPublicId, true);
            $this->assertSectionBelongsToLevel($section, $level);
            $this->assertSectionUnique($level, $data['name'], $data['code'] ?? null, $data['sequence'], (int) $section->getKey());
            $section->fill(['name' => trim($data['name']), 'code' => $this->nullableString($data['code'] ?? null), 'sequence' => $data['sequence']]);
            $section->save();
            $this->audit($actor, 'academic.section_updated', (string) $section->public_id, ['level_id' => (string) $level->public_id, 'status' => $section->status]);

            return $this->sectionData($section);
        });
    }

    /** @return array<string, mixed> */
    public function transitionSection(UserIdentity $actor, string $school, string $levelPublicId, string $sectionPublicId, string $status): array
    {
        $this->authorize($actor, $school, 'academic.structure.manage');

        return DB::transaction(function () use ($actor, $levelPublicId, $sectionPublicId, $status): array {
            $level = $this->findLevel($levelPublicId, true);
            $section = $this->findSection($sectionPublicId, true);
            $this->assertSectionBelongsToLevel($section, $level);
            if ((string) $section->status === $status) {
                return $this->sectionData($section);
            }
            $section->status = $status;
            $section->save();
            $this->audit($actor, 'academic.section_'.$status, (string) $section->public_id, ['level_id' => (string) $level->public_id, 'status' => $status]);

            return $this->sectionData($section);
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function findLevel(string $publicId, bool $lock = false): AcademicLevel
    {
        $query = AcademicLevel::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $level = $query->first();
        if (! $level instanceof AcademicLevel) {
            throw new ModelNotFoundException;
        }

        return $level;
    }

    private function findSection(string $publicId, bool $lock = false): AcademicSection
    {
        $query = AcademicSection::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $section = $query->first();
        if (! $section instanceof AcademicSection) {
            throw new ModelNotFoundException;
        }

        return $section;
    }

    private function assertSectionBelongsToLevel(AcademicSection $section, AcademicLevel $level): void
    {
        if ((int) $section->academic_level_id !== (int) $level->getKey()) {
            throw new ModelNotFoundException;
        }
    }

    private function assertLevelUnique(string $name, ?string $code, int $sequence, ?int $exceptId = null): void
    {
        $query = AcademicLevel::query()->where(function ($query) use ($name, $code, $sequence): void {
            $query->where('name', trim($name))->orWhere('sequence', $sequence);
            if ($code !== null && trim($code) !== '') {
                $query->orWhere('code', trim($code));
            }
        });
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidStructure('The academic level name, code or sequence is already in use.');
        }
    }

    private function assertSectionUnique(AcademicLevel $level, string $name, ?string $code, int $sequence, ?int $exceptId = null): void
    {
        $query = $level->sections()->where(function ($query) use ($name, $code, $sequence): void {
            $query->where('name', trim($name))->orWhere('sequence', $sequence);
            if ($code !== null && trim($code) !== '') {
                $query->orWhere('code', trim($code));
            }
        });
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidStructure('The academic section name, code or sequence is already in use.');
        }
    }

    private function invalidStructure(string $message): ValidationException
    {
        return ValidationException::withMessages(['academic_structure' => [$message]]);
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
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_structure', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.structure.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function levelData(AcademicLevel $level): array
    {
        return ['id' => (string) $level->public_id, 'name' => (string) $level->name, 'code' => $this->nullableString($level->getAttribute('code')), 'sequence' => (int) $level->sequence, 'stage' => $this->nullableString($level->getAttribute('stage')), 'status' => (string) $level->status];
    }

    /** @return array<string, mixed> */
    private function sectionData(AcademicSection $section): array
    {
        return ['id' => (string) $section->public_id, 'level_id' => (string) $section->level?->public_id, 'name' => (string) $section->name, 'code' => $this->nullableString($section->getAttribute('code')), 'sequence' => (int) $section->sequence, 'status' => (string) $section->status];
    }
}
