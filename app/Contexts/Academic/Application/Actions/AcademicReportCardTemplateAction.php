<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplate;
use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplateField;
use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplateSection;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AcademicReportCardTemplateAction
{
    public function __construct(private readonly SchoolPermissionChecker $permissions, private readonly RecordAuditEventAction $audit) {}

    /** @return array{templates: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $session, string $term): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.templates.read');

        return ['templates' => AcademicReportCardTemplate::query()->where('academic_session_id', $academicSession->getKey())->where('academic_term_id', $academicTerm->getKey())->with(['snapshot', 'session', 'term'])->orderByDesc('version')->get()->map(fn (AcademicReportCardTemplate $template): array => $this->summary($template))->all()];
    }

    /** @param array<string, mixed> $data
     * @return array{template: array<string, mixed>}
     */
    public function create(UserIdentity $actor, string $school, string $session, string $term, array $data): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.templates.manage');
        $snapshot = $this->snapshot($data['snapshot_id'], $academicTerm);

        return DB::transaction(function () use ($actor, $academicSession, $academicTerm, $snapshot, $data): array {
            $version = ((int) AcademicReportCardTemplate::query()->where('academic_session_id', $academicSession->getKey())->where('academic_term_id', $academicTerm->getKey())->max('version')) + 1;
            $template = AcademicReportCardTemplate::query()->create(['school_id' => TenantContext::require()->schoolId, 'academic_session_id' => $academicSession->getKey(), 'academic_term_id' => $academicTerm->getKey(), 'academic_result_snapshot_id' => $snapshot->getKey(), 'version' => $version, 'name' => trim($data['name']), 'status' => 'draft', 'created_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
            $this->replaceSections($template, $data['sections']);
            $this->audit($actor, 'academic.report_card_template_created', (string) $template->public_id, ['to' => 'draft', 'version' => $version]);

            return ['template' => $this->data($template->load('sections.fields', 'snapshot', 'session', 'term'))];
        });
    }

    /** @param array<string, mixed> $data
     * @return array{template: array<string, mixed>}
     */
    public function update(UserIdentity $actor, string $school, string $session, string $term, string $templateId, array $data): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.templates.manage');
        $template = $this->template($templateId, $academicSession, $academicTerm);
        if ($template->status !== 'draft') {
            throw ValidationException::withMessages(['template' => ['Only draft templates can be edited.']]);
        }
        $snapshot = $this->snapshot($data['snapshot_id'], $academicTerm);

        return DB::transaction(function () use ($actor, $template, $snapshot, $data): array {
            $template = AcademicReportCardTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            if ($template->status !== 'draft') {
                throw ValidationException::withMessages(['template' => ['Only draft templates can be edited.']]);
            }
            $template->update(['name' => trim($data['name']), 'academic_result_snapshot_id' => $snapshot->getKey(), 'request_id' => Context::get('request_id')]);
            $template->sections()->delete();
            $this->replaceSections($template, $data['sections']);
            $this->audit($actor, 'academic.report_card_template_updated', (string) $template->public_id, ['to' => 'draft']);

            return ['template' => $this->data($template->load('sections.fields', 'snapshot', 'session', 'term'))];
        });
    }

    /** @return array{template: array<string, mixed>} */
    public function show(UserIdentity $actor, string $school, string $session, string $term, string $templateId): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.templates.read');

        return ['template' => $this->data($this->template($templateId, $academicSession, $academicTerm)->load('sections.fields', 'snapshot', 'session', 'term'))];
    }

    /** @return array{template: array<string, mixed>} */
    public function activate(UserIdentity $actor, string $school, string $session, string $term, string $templateId): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.templates.manage');

        return DB::transaction(function () use ($actor, $academicSession, $academicTerm, $templateId): array {
            $template = $this->template($templateId, $academicSession, $academicTerm, true);
            if ($template->status === 'active') {
                return ['template' => $this->data($template->load('sections.fields', 'snapshot', 'session', 'term'))];
            }
            if ($template->status !== 'draft') {
                throw ValidationException::withMessages(['template' => ['Only draft templates can be activated.']]);
            }
            $active = AcademicReportCardTemplate::query()->where('academic_session_id', $academicSession->getKey())->where('academic_term_id', $academicTerm->getKey())->where('status', 'active')->lockForUpdate()->first();
            if ($active instanceof AcademicReportCardTemplate) {
                throw ValidationException::withMessages(['template' => ['Only one active report-card template is allowed for the term.']]);
            }
            $template->update(['status' => 'active', 'activated_by' => $actor->getKey(), 'activated_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.report_card_template_activated', (string) $template->public_id, ['from' => 'draft', 'to' => 'active']);

            return ['template' => $this->data($template->fresh()->load('sections.fields', 'snapshot', 'session', 'term'))];
        });
    }

    /** @return array{template: array<string, mixed>} */
    public function retire(UserIdentity $actor, string $school, string $session, string $term, string $templateId): array
    {
        [$academicSession, $academicTerm] = $this->authorizeTerm($actor, $school, $session, $term, 'academic.results.templates.manage');

        return DB::transaction(function () use ($actor, $academicSession, $academicTerm, $templateId): array {
            $template = $this->template($templateId, $academicSession, $academicTerm, true);
            if ($template->status === 'retired') {
                return ['template' => $this->data($template->load('sections.fields', 'snapshot', 'session', 'term'))];
            }
            if ($template->status !== 'active') {
                throw ValidationException::withMessages(['template' => ['Only active templates can be retired.']]);
            }
            $template->update(['status' => 'retired', 'retired_by' => $actor->getKey(), 'retired_at' => now(), 'request_id' => Context::get('request_id')]);
            $this->audit($actor, 'academic.report_card_template_retired', (string) $template->public_id, ['from' => 'active', 'to' => 'retired']);

            return ['template' => $this->data($template->fresh()->load('sections.fields', 'snapshot', 'session', 'term'))];
        });
    }

    /** @return array{0: AcademicSession, 1: AcademicTerm} */
    private function authorizeTerm(UserIdentity $actor, string $school, string $session, string $term, string $permission): array
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
        $academicSession = AcademicSession::query()->where('school_id', $context->schoolId)->where('public_id', $session)->first();
        $academicTerm = AcademicTerm::query()->where('school_id', $context->schoolId)->where('academic_session_id', $academicSession?->getKey())->where('public_id', $term)->first();
        if (! $academicSession instanceof AcademicSession || ! $academicTerm instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return [$academicSession, $academicTerm];
    }

    private function snapshot(string $snapshotId, AcademicTerm $term): AcademicResultSnapshot
    {
        $snapshot = AcademicResultSnapshot::query()->where('academic_term_id', $term->getKey())->where('public_id', $snapshotId)->where('status', 'published')->whereHas('gate', fn ($query) => $query->where('status', 'published'))->first();
        if (! $snapshot instanceof AcademicResultSnapshot) {
            throw new ModelNotFoundException;
        }

        return $snapshot;
    }

    private function template(string $id, AcademicSession $session, AcademicTerm $term, bool $lock = false): AcademicReportCardTemplate
    {
        $query = AcademicReportCardTemplate::query()->where('school_id', TenantContext::require()->schoolId)->where('academic_session_id', $session->getKey())->where('academic_term_id', $term->getKey())->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @param list<array<string, mixed>> $sections */
    private function replaceSections(AcademicReportCardTemplate $template, array $sections): void
    {
        $keys = $sequences = [];
        foreach ($sections as $section) {
            if (isset($keys[$section['key']]) || isset($sequences[$section['sequence']])) {
                throw ValidationException::withMessages(['sections' => ['Section keys and sequences must be unique.']]);
            }
            $keys[$section['key']] = true;
            $sequences[$section['sequence']] = true;
            $record = $template->sections()->create(['public_id' => (string) Str::uuid7(), 'section_key' => $section['key'], 'label' => trim($section['label']), 'sequence' => $section['sequence'], 'is_visible' => $section['is_visible'] ?? true]);
            $fieldKeys = $fieldSequences = [];
            foreach ($section['fields'] as $field) {
                if (isset($fieldKeys[$field['key']]) || isset($fieldSequences[$field['sequence']])) {
                    throw ValidationException::withMessages(['sections' => ['Field keys and sequences must be unique within a section.']]);
                }
                $fieldKeys[$field['key']] = true;
                $fieldSequences[$field['sequence']] = true;
                $record->fields()->create(['public_id' => (string) Str::uuid7(), 'field_key' => $field['key'], 'label' => trim($field['label']), 'field_type' => $field['type'], 'sequence' => $field['sequence'], 'is_visible' => $field['is_visible'] ?? true]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function summary(AcademicReportCardTemplate $template): array
    {
        return ['id' => (string) $template->public_id, 'session_id' => (string) $template->session?->public_id, 'term_id' => (string) $template->term?->public_id, 'snapshot_id' => (string) $template->snapshot?->public_id, 'name' => (string) $template->name, 'version' => (int) $template->version, 'status' => (string) $template->status, 'created_at' => $template->created_at?->toISOString()];
    }

    /** @return array<string, mixed> */
    private function data(AcademicReportCardTemplate $template): array
    {
        return [...$this->summary($template), 'activated_at' => $this->date($template->activated_at), 'retired_at' => $this->date($template->retired_at), 'sections' => $template->sections->map(fn (AcademicReportCardTemplateSection $section): array => ['id' => (string) $section->public_id, 'key' => (string) $section->section_key, 'label' => (string) $section->label, 'sequence' => (int) $section->sequence, 'is_visible' => (bool) $section->is_visible, 'fields' => $section->fields->map(fn (AcademicReportCardTemplateField $field): array => ['id' => (string) $field->public_id, 'key' => (string) $field->field_key, 'label' => (string) $field->label, 'type' => (string) $field->field_type, 'sequence' => (int) $field->sequence, 'is_visible' => (bool) $field->is_visible])->all()])->all()];
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    /** @param array<string, mixed> $transition */
    private function audit(UserIdentity $actor, string $action, string $publicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(schoolId: $context->schoolId, actorId: $actor->getKey(), action: $action, subjectType: 'academic_report_card_template', subjectPublicId: $publicId, stateTransition: $transition, metadata: ['request_id' => Context::get('request_id')]));
    }
}
