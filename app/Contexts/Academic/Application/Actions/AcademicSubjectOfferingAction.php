<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicSubject;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicSubjectOfferingAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /**
     * @return array{items: list<array{
     *     id: string,
     *     session_id: string,
     *     term_id: string,
     *     level_id: string,
     *     section_id: string,
     *     class_arm_id: string,
     *     subject_id: string,
     *     display_order: int,
     *     status: string,
     * }>}
     */
    public function index(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId): array
    {
        [$session, $term] = $this->authorizeTerm($actor, $school, $sessionPublicId, $termPublicId, 'academic.offerings.read');

        return ['items' => AcademicSubjectOffering::query()
            ->where('academic_session_id', $session->getKey())
            ->where('academic_term_id', $term->getKey())
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AcademicSubjectOffering $offering): array => $this->data($offering))
            ->all()];
    }

    /**
     * @return array{
     *     id: string,
     *     session_id: string,
     *     term_id: string,
     *     level_id: string,
     *     section_id: string,
     *     class_arm_id: string,
     *     subject_id: string,
     *     display_order: int,
     *     status: string,
     * }
     */
    public function show(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId, string $offeringPublicId): array
    {
        [$session, $term] = $this->authorizeTerm($actor, $school, $sessionPublicId, $termPublicId, 'academic.offerings.read');

        return $this->data($this->find($offeringPublicId, $session, $term));
    }

    /** @param array{class_arm_id: string, subject_id: string, display_order?: int|null} $data
     * @return array{
     *     id: string,
     *     session_id: string,
     *     term_id: string,
     *     level_id: string,
     *     section_id: string,
     *     class_arm_id: string,
     *     subject_id: string,
     *     display_order: int,
     *     status: string,
     * }
     */
    public function create(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId, array $data): array
    {
        [$session, $term] = $this->authorizeTerm($actor, $school, $sessionPublicId, $termPublicId, 'academic.offerings.manage');
        $this->assertTermOpen($term);

        return DB::transaction(function () use ($actor, $session, $term, $data): array {
            [$level, $section, $classArm, $subject] = $this->resolveOfferingParents((string) $data['class_arm_id'], (string) $data['subject_id']);
            $this->assertParentsActive($session, $term, $level, $section, $classArm, $subject);
            $this->assertUnique($term, $classArm, $subject);
            $offering = AcademicSubjectOffering::query()->create([
                'academic_session_id' => $session->getKey(),
                'academic_term_id' => $term->getKey(),
                'academic_level_id' => $level->getKey(),
                'academic_section_id' => $section->getKey(),
                'academic_class_arm_id' => $classArm->getKey(),
                'academic_subject_id' => $subject->getKey(),
                'display_order' => $data['display_order'] ?? null,
                'status' => 'active',
            ]);
            $this->audit($actor, 'academic.subject_offering_created', (string) $offering->public_id, ['status' => 'active']);

            return $this->data($offering);
        });
    }

    /** @param array{display_order?: int|null} $data
     * @return array{
     *     id: string,
     *     session_id: string,
     *     term_id: string,
     *     level_id: string,
     *     section_id: string,
     *     class_arm_id: string,
     *     subject_id: string,
     *     display_order: int,
     *     status: string,
     * }
     */
    public function update(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId, string $offeringPublicId, array $data): array
    {
        [$session, $term] = $this->authorizeTerm($actor, $school, $sessionPublicId, $termPublicId, 'academic.offerings.manage');
        $this->assertTermOpen($term);

        return DB::transaction(function () use ($actor, $session, $term, $offeringPublicId, $data): array {
            $offering = $this->find($offeringPublicId, $session, $term, true);
            $offering->display_order = $data['display_order'] ?? null;
            $offering->save();
            $this->audit($actor, 'academic.subject_offering_updated', (string) $offering->public_id, ['status' => $offering->status]);

            return $this->data($offering);
        });
    }

    /**
     * @return array{
     *     id: string,
     *     session_id: string,
     *     term_id: string,
     *     level_id: string,
     *     section_id: string,
     *     class_arm_id: string,
     *     subject_id: string,
     *     display_order: int,
     *     status: string,
     * }
     */
    public function transition(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId, string $offeringPublicId, string $status): array
    {
        [$session, $term] = $this->authorizeTerm($actor, $school, $sessionPublicId, $termPublicId, 'academic.offerings.manage');
        $this->assertTermOpen($term);

        return DB::transaction(function () use ($actor, $session, $term, $offeringPublicId, $status): array {
            $offering = $this->find($offeringPublicId, $session, $term, true);
            if ((string) $offering->status === $status) {
                return $this->data($offering);
            }
            if ($status === 'active') {
                $this->assertParentsActive($session, $term, $offering->level, $offering->section, $offering->classArm, $offering->subject);
            }
            $offering->status = $status;
            $offering->save();
            $this->audit($actor, 'academic.subject_offering_'.$status, (string) $offering->public_id, ['status' => $status]);

            return $this->data($offering);
        });
    }

    /** @return array{0: AcademicSession, 1: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $sessionPublicId, string $termPublicId, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $session = AcademicSession::query()->where('public_id', $sessionPublicId)->first();
        $term = AcademicTerm::query()->where('public_id', $termPublicId)->where('academic_session_id', $session?->getKey())->first();
        if (! $session instanceof AcademicSession || ! $term instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return [$session, $term];
    }

    /** @return array{0: AcademicLevel, 1: AcademicSection, 2: AcademicClassArm, 3: AcademicSubject} */
    private function resolveOfferingParents(string $classArmPublicId, string $subjectPublicId): array
    {
        $classArm = AcademicClassArm::query()->where('public_id', $classArmPublicId)->first();
        $subject = AcademicSubject::query()->where('public_id', $subjectPublicId)->first();
        if (! $classArm instanceof AcademicClassArm || ! $subject instanceof AcademicSubject) {
            throw new ModelNotFoundException;
        }
        $level = AcademicLevel::query()->whereKey($classArm->academic_level_id)->first();
        $section = AcademicSection::query()->whereKey($classArm->academic_section_id)->first();
        if (! $level instanceof AcademicLevel || ! $section instanceof AcademicSection || (int) $section->academic_level_id !== (int) $level->getKey()) {
            throw new ModelNotFoundException;
        }

        return [$level, $section, $classArm, $subject];
    }

    private function assertParentsActive(AcademicSession $session, AcademicTerm $term, AcademicLevel $level, AcademicSection $section, AcademicClassArm $classArm, AcademicSubject $subject): void
    {
        if ($session->status === 'closed' || $term->status === 'closed' || $level->status !== 'active' || $section->status !== 'active' || $classArm->status !== 'active' || $subject->status !== 'active') {
            throw $this->invalidOffering('Offerings require active academic structures and an open term.');
        }
    }

    private function assertTermOpen(AcademicTerm $term): void
    {
        if ($term->status === 'closed') {
            throw $this->invalidOffering('Closed terms cannot be changed.');
        }
    }

    private function assertUnique(AcademicTerm $term, AcademicClassArm $classArm, AcademicSubject $subject): void
    {
        if (AcademicSubjectOffering::query()->where('academic_term_id', $term->getKey())->where('academic_class_arm_id', $classArm->getKey())->where('academic_subject_id', $subject->getKey())->exists()) {
            throw $this->invalidOffering('The subject is already offered for this class arm and term.');
        }
    }

    private function find(string $publicId, AcademicSession $session, AcademicTerm $term, bool $lock = false): AcademicSubjectOffering
    {
        $query = AcademicSubjectOffering::query()->where('public_id', $publicId)->where('academic_session_id', $session->getKey())->where('academic_term_id', $term->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $offering = $query->first();
        if (! $offering instanceof AcademicSubjectOffering) {
            throw new ModelNotFoundException;
        }

        return $offering;
    }

    private function invalidOffering(string $message): ValidationException
    {
        return ValidationException::withMessages(['academic_subject_offering' => [$message]]);
    }

    /** @param array<string, scalar|null> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_subject_offering', subjectPublicId: $subjectPublicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.offerings.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /**
     * @return array{
     *     id: string,
     *     session_id: string,
     *     term_id: string,
     *     level_id: string,
     *     section_id: string,
     *     class_arm_id: string,
     *     subject_id: string,
     *     display_order: int,
     *     status: string,
     * }
     */
    private function data(AcademicSubjectOffering $offering): array
    {
        return [
            'id' => (string) $offering->public_id,
            'session_id' => (string) $offering->session?->public_id,
            'term_id' => (string) $offering->term?->public_id,
            'level_id' => (string) $offering->level?->public_id,
            'section_id' => (string) $offering->section?->public_id,
            'class_arm_id' => (string) $offering->classArm?->public_id,
            'subject_id' => (string) $offering->subject?->public_id,
            'display_order' => $this->nullableInteger($offering->getAttribute('display_order')),
            'status' => (string) $offering->status,
        ];
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
