<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentComponent;
use App\Contexts\Academic\Domain\Models\AcademicAssessmentScheme;
use App\Contexts\Academic\Domain\Models\AcademicSession;
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

final class AcademicAssessmentSchemeAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{scheme: array<string, mixed>|null} */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $offering): array
    {
        [$academicSession, $academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessment-components.read');
        $scheme = AcademicAssessmentScheme::query()
            ->where('academic_term_id', $academicTerm->getKey())
            ->where('academic_subject_offering_id', $subjectOffering->getKey())
            ->with('components')
            ->first();

        return ['scheme' => $scheme === null ? null : $this->data($scheme, $academicSession, $academicTerm, $subjectOffering)];
    }

    /** @param array{name: string, total_marks: int, components: list<array{name: string, category: string, max_marks: int, sequence: int}>} $data
     * @return array{scheme: array<string, mixed>}
     */
    public function replace(UserIdentity $actor, string $school, string $session, string $term, string $offering, array $data): array
    {
        [$academicSession, $academicTerm, $subjectOffering] = $this->authorizeOffering($actor, $school, $session, $term, $offering, 'academic.assessment-components.manage');
        $this->assertOpen($academicSession, $academicTerm, $subjectOffering);
        $this->validateComponents($data);

        return DB::transaction(function () use ($actor, $academicSession, $academicTerm, $subjectOffering, $data): array {
            $academicTerm = AcademicTerm::query()->whereKey($academicTerm->getKey())->lockForUpdate()->firstOrFail();
            $subjectOffering = AcademicSubjectOffering::query()->whereKey($subjectOffering->getKey())->lockForUpdate()->firstOrFail();
            $academicSession = AcademicSession::query()->whereKey($academicSession->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOpen($academicSession, $academicTerm, $subjectOffering);

            $scheme = AcademicAssessmentScheme::query()
                ->where('academic_term_id', $academicTerm->getKey())
                ->where('academic_subject_offering_id', $subjectOffering->getKey())
                ->lockForUpdate()
                ->first();
            $action = $scheme === null ? 'academic.assessment_scheme_created' : 'academic.assessment_scheme_replaced';
            $scheme ??= new AcademicAssessmentScheme;
            $scheme->fill([
                'school_id' => TenantContext::require()->schoolId,
                'academic_session_id' => $academicSession->getKey(),
                'academic_term_id' => $academicTerm->getKey(),
                'academic_subject_offering_id' => $subjectOffering->getKey(),
                'name' => trim($data['name']),
                'total_marks' => $data['total_marks'],
            ]);
            $scheme->save();
            $scheme->components()->delete();
            foreach ($data['components'] as $component) {
                AcademicAssessmentComponent::query()->create([
                    'school_id' => TenantContext::require()->schoolId,
                    'academic_assessment_scheme_id' => $scheme->getKey(),
                    'name' => trim($component['name']),
                    'category' => $component['category'],
                    'max_marks' => $component['max_marks'],
                    'sequence' => $component['sequence'],
                ]);
            }
            $this->audit($actor, $action, (string) $scheme->public_id, ['total_marks' => (int) $scheme->total_marks, 'component_count' => count($data['components'])]);

            return ['scheme' => $this->data($scheme->load('components'), $academicSession, $academicTerm, $subjectOffering)];
        });
    }

    /** @return array{0: AcademicSession, 1: AcademicTerm, 2: AcademicSubjectOffering} */
    private function authorizeOffering(UserIdentity $actor, string $school, string $session, string $term, string $offering, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicSession = AcademicSession::query()->where('public_id', $session)->first();
        $academicTerm = AcademicTerm::query()->where('public_id', $term)->where('academic_session_id', $academicSession?->getKey())->first();
        $subjectOffering = AcademicSubjectOffering::query()->where('public_id', $offering)->where('academic_term_id', $academicTerm?->getKey())->first();
        if (! $academicSession instanceof AcademicSession || ! $academicTerm instanceof AcademicTerm || ! $subjectOffering instanceof AcademicSubjectOffering) {
            throw new ModelNotFoundException;
        }

        return [$academicSession, $academicTerm, $subjectOffering];
    }

    private function assertOpen(AcademicSession $session, AcademicTerm $term, AcademicSubjectOffering $offering): void
    {
        if ($session->status === 'closed' || $term->status === 'closed' || $offering->status !== 'active') {
            throw ValidationException::withMessages(['assessment_scheme' => ['Assessment configuration requires an active offering and an open term.']]);
        }
    }

    /** @param array{name: string, total_marks: int, components: list<array{name: string, category: string, max_marks: int, sequence: int}>} $data */
    private function validateComponents(array $data): void
    {
        $names = [];
        $sequences = [];
        $total = 0;
        foreach ($data['components'] as $component) {
            $name = mb_strtolower(trim($component['name']));
            if (isset($names[$name])) {
                throw ValidationException::withMessages(['components' => ['Component names must be unique within the scheme.']]);
            }
            if (isset($sequences[$component['sequence']])) {
                throw ValidationException::withMessages(['components' => ['Component sequence values must be unique within the scheme.']]);
            }
            $names[$name] = true;
            $sequences[$component['sequence']] = true;
            $total += $component['max_marks'];
        }
        if ($total !== $data['total_marks']) {
            throw ValidationException::withMessages(['total_marks' => ['Component maximum marks must equal total marks.']]);
        }
    }

    /** @param array<string, int> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'academic_assessment_scheme', subjectPublicId: $publicId, schoolId: $context->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'academic.assessment-components.manage'], stateTransition: $transition, requiresSchoolContext: true));
    }

    /** @return array<string, mixed> */
    private function data(AcademicAssessmentScheme $scheme, AcademicSession $session, AcademicTerm $term, AcademicSubjectOffering $offering): array
    {
        return [
            'id' => (string) $scheme->public_id,
            'session_id' => (string) $session->public_id,
            'term_id' => (string) $term->public_id,
            'offering_id' => (string) $offering->public_id,
            'name' => (string) $scheme->name,
            'total_marks' => (int) $scheme->total_marks,
            'components' => $scheme->components->sortBy('sequence')->values()->map(fn (AcademicAssessmentComponent $component): array => [
                'id' => (string) $component->public_id,
                'name' => (string) $component->name,
                'category' => (string) $component->category,
                'max_marks' => (int) $component->max_marks,
                'sequence' => (int) $component->sequence,
            ])->all(),
        ];
    }
}
