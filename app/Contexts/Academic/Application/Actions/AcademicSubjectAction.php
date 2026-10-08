<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicSubject;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicSubjectAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school): array
    {
        $this->authorize($actor, $school, 'academic.subjects.read');

        return ['items' => AcademicSubject::query()->orderBy('name')->get()->map(fn (AcademicSubject $subject): array => $this->data($subject))->all()];
    }

    /** @return array<string, mixed> */
    public function show(UserIdentity $actor, string $school, string $subjectPublicId): array
    {
        $this->authorize($actor, $school, 'academic.subjects.read');

        return $this->data($this->find($subjectPublicId));
    }

    /** @param array{name: string, code?: string|null, classification?: string|null} $data
     * @return array<string, mixed>
     */
    public function create(UserIdentity $actor, string $school, array $data): array
    {
        $this->authorize($actor, $school, 'academic.subjects.manage');

        return DB::transaction(function () use ($actor, $data): array {
            $this->assertUnique($data['name'], $data['code'] ?? null);
            $subject = AcademicSubject::query()->create([
                'name' => trim($data['name']),
                'code' => $this->nullableString($data['code'] ?? null),
                'classification' => $this->nullableString($data['classification'] ?? null),
                'status' => 'active',
            ]);
            $this->audit($actor, 'academic.subject_created', (string) $subject->public_id, ['status' => 'active']);

            return $this->data($subject);
        });
    }

    /** @param array{name: string, code?: string|null, classification?: string|null} $data
     * @return array<string, mixed>
     */
    public function update(UserIdentity $actor, string $school, string $subjectPublicId, array $data): array
    {
        $this->authorize($actor, $school, 'academic.subjects.manage');

        return DB::transaction(function () use ($actor, $subjectPublicId, $data): array {
            $subject = $this->find($subjectPublicId, true);
            $this->assertUnique($data['name'], $data['code'] ?? null, (int) $subject->getKey());
            $subject->fill([
                'name' => trim($data['name']),
                'code' => $this->nullableString($data['code'] ?? null),
                'classification' => $this->nullableString($data['classification'] ?? null),
            ]);
            $subject->save();
            $this->audit($actor, 'academic.subject_updated', (string) $subject->public_id, ['status' => $subject->status]);

            return $this->data($subject);
        });
    }

    /** @return array<string, mixed> */
    public function transition(UserIdentity $actor, string $school, string $subjectPublicId, string $status): array
    {
        $this->authorize($actor, $school, 'academic.subjects.manage');

        return DB::transaction(function () use ($actor, $subjectPublicId, $status): array {
            $subject = $this->find($subjectPublicId, true);
            if ((string) $subject->status === $status) {
                return $this->data($subject);
            }
            $subject->status = $status;
            $subject->save();
            $this->audit($actor, 'academic.subject_'.$status, (string) $subject->public_id, ['status' => $status]);

            return $this->data($subject);
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function find(string $publicId, bool $lock = false): AcademicSubject
    {
        $query = AcademicSubject::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $subject = $query->first();
        if (! $subject instanceof AcademicSubject) {
            throw new ModelNotFoundException;
        }

        return $subject;
    }

    private function assertUnique(string $name, ?string $code, ?int $exceptId = null): void
    {
        $query = AcademicSubject::query()->where(function ($query) use ($name, $code): void {
            $query->where('name', trim($name));
            if ($code !== null && trim($code) !== '') {
                $query->orWhere('code', trim($code));
            }
        });
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['academic_subject' => ['The subject name or code is already in use.']]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @param array<string, scalar|null> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_subject', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.subjects.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(AcademicSubject $subject): array
    {
        return ['id' => (string) $subject->public_id, 'name' => (string) $subject->name, 'code' => $this->nullableString($subject->getAttribute('code')), 'classification' => $this->nullableString($subject->getAttribute('classification')), 'status' => (string) $subject->status];
    }
}
